<?php

declare(strict_types=1);

namespace DanCenter\RabbitTransport;

use DanCenter\RabbitTransport\DTOs\RabbitMessageDTO;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

/**
 * Публикатор сообщений в RabbitMQ exchange с publisher confirms.
 *
 * Connection и таймаут подтверждения параметризованы через config пакета
 * (rabbit-transport.connection / publish_confirm_timeout). Пакет не зависит
 * от классов приложения.
 */
final readonly class RabbitMQPublisher
{
    /**
     * Отправляет сообщение в RabbitMQ с подтверждением доставки.
     *
     * Шаги:
     * 1. Резолвит destination (routing key): аргумент → outbound-конфиг → имя события.
     * 2. Сериализует DTO в JSON с JSON_UNESCAPED_UNICODE (читаемая кириллица).
     * 3. Открывает канал connection пакета и включает confirm_select.
     * 4. Публикует raw-сообщение и ждёт подтверждения (publisher confirms).
     * 5. При ошибке логирует контекст и возвращает false.
     *
     * @param  RabbitMessageDTO  $message  Сообщение для публикации.
     * @param  string  $routingKey  Per-message routing key (токен (а) контракта).
     * @return bool true при подтверждённой публикации, иначе false.
     */
    public function publish(RabbitMessageDTO $message, string $routingKey = ''): bool
    {
        $connectionName = (string) config('rabbit-transport.connection', 'rabbitmq_inbox');
        $timeout = (float) config('rabbit-transport.publish_confirm_timeout', 5.0);
        $destination = $this->resolveDestination($message, $routingKey);

        try {
            $payload = json_encode($message, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            $connection = Queue::connection($connectionName);
            $channel = $connection->getChannel();

            $channel->confirm_select();
            $connection->pushRaw($payload, $destination);
            $channel->wait_for_pending_acks_returns($timeout);

            return true;
        } catch (\Throwable $e) {
            Log::error('RabbitMQ: Failed to publish message with publisher confirms', [
                'connection' => $connectionName,
                'event' => $message->name,
                'routing_key' => $destination,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return false;
        }
    }

    /**
     * Резолвит destination (routing key) для сообщения.
     *
     * Шаги:
     * 1. Если передан явный per-message routing key — используем его.
     * 2. Иначе берём дефолтный routing key из outbound-конфига по имени события.
     * 3. Иначе fallback на само имя события (с предупреждением в лог).
     */
    private function resolveDestination(RabbitMessageDTO $message, string $routingKey): string
    {
        if ($routingKey !== '') {
            return $routingKey;
        }

        $default = config('rabbit-transport.outbound.'.$message->name);
        if (is_string($default) && $default !== '') {
            return $default;
        }

        Log::warning('RabbitMQ: No routing key resolved for outbound event; using event name', [
            'event' => $message->name,
        ]);

        return $message->name;
    }
}
