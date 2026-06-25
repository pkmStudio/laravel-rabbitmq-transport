<?php

declare(strict_types=1);

namespace DanCenter\RabbitTransport\Consumers;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use PhpAmqpLib\Exception\AMQPProtocolChannelException;
use VladimirYuldashev\LaravelQueueRabbitMQ\Queue\Jobs\RabbitMQJob;

/**
 * Потребитель входящих сообщений из RabbitMQ.
 *
 * Диспетчеризация выполняется через config-driven реестр
 * `rabbit-transport.inbound`: имя события (поле `name` в теле) → [Class, Method].
 * Пакет не ссылается на классы приложения — реестр объявляет приложение.
 *
 * Класс не помечен final: позволяет частичное мокирование job-контекстных
 * методов (getRawBody/delete/release) в тестах пакета.
 */
class InboxConsumer extends RabbitMQJob
{
    /**
     * Обрабатывает входящее сообщение из очереди.
     *
     * Шаги:
     * 1. Извлекает имя события из payload.
     * 2. Резолвит обработчик [Class, Method] из реестра rabbit-transport.inbound.
     * 3. При отсутствии имени/обработчика — логирует и удаляет сообщение.
     * 4. Создаёт обработчик через контейнер и вызывает метод с данными.
     * 5. При успехе удаляет сообщение из очереди.
     * 6. При ошибке логирует контекст и возвращает сообщение в очередь (release).
     */
    public function fire(): void
    {
        $payload = $this->payload();
        $eventName = $payload['displayName'] ?? null;

        try {
            if (! $eventName) {
                Log::error('RabbitMQ: Missing event name in payload', $payload);
                $this->delete();

                return;
            }

            $handler = $this->resolveHandler($eventName);
            if ($handler === null) {
                Log::error("RabbitMQ: No handler registered for event: {$eventName}", $payload);
                $this->delete();

                return;
            }

            [$class, $method] = $handler;
            app($class)->{$method}($payload['data']);

            $this->delete();
        } catch (\Throwable $e) {
            Log::error("RabbitMQ Consumer Error [{$eventName}]: ".$e->getMessage(), [
                'payload' => $payload,
                'trace' => $e->getTrace(),
            ]);

            $delay = (int) config('rabbit-transport.consumer.release_delay', 20);

            try {
                $this->release($delay);
            } catch (AMQPProtocolChannelException $releaseError) {
                Log::error("RabbitMQ Release Error [{$eventName}]: ".$releaseError->getMessage(), [
                    'payload' => $payload,
                    'trace' => $releaseError->getTrace(),
                ]);
            }
        }
    }

    /**
     * Возвращает имя задачи для воркера.
     *
     * Шаги:
     * 1. Берёт имя события из payload (чтобы воркер не искал ключ "job" в JSON).
     * 2. Если имени нет — возвращает имя класса.
     */
    public function getName(): string
    {
        return $this->payload()['displayName'] ?? self::class;
    }

    /**
     * Преобразует raw body сообщения в стандартизированный payload.
     *
     * Шаги:
     * 1. Декодирует JSON тела сообщения.
     * 2. Берёт id (или генерирует UUID).
     * 3. Берёт имя события из поля `name` (или `displayName`).
     * 4. Берёт данные из поля `body` (или `data`).
     *
     * @return array{id: string, displayName: string, data: array<string, mixed>}
     */
    public function payload(): array
    {
        $rawData = json_decode($this->getRawBody(), true) ?: [];

        return [
            'id' => $rawData['id'] ?? Str::uuid()->toString(),
            'displayName' => $rawData['name'] ?? $rawData['displayName'] ?? '',
            'data' => $rawData['body'] ?? $rawData['data'] ?? [],
        ];
    }

    /**
     * Резолвит обработчик события из config-driven реестра.
     *
     * Шаги:
     * 1. Читает карту rabbit-transport.inbound.
     * 2. Возвращает пару [class-string, method] для имени события или null.
     *
     * @return array{0: class-string, 1: string}|null
     */
    private function resolveHandler(string $eventName): ?array
    {
        $registry = (array) config('rabbit-transport.inbound', []);
        $handler = $registry[$eventName] ?? null;

        if (is_array($handler) && count($handler) === 2) {
            return [$handler[0], $handler[1]];
        }

        return null;
    }
}
