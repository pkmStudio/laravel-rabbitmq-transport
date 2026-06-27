<?php

declare(strict_types=1);

namespace DanCenter\RabbitTransport\Consumers;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use PhpAmqpLib\Exception\AMQPProtocolChannelException;
use Throwable;
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
     * 6. При ошибке применяет retry/poison-стратегию из конфигурации.
     */
    public function fire(): void
    {
        $payload = $this->payload();
        $eventName = $payload['displayName'] ?? null;

        Log::debug('RabbitMQ: InboxConsumer started', $this->messageContext($eventName, $payload));

        try {
            if (! $eventName) {
                Log::error('RabbitMQ: Missing event name in payload', $this->messageContext($eventName, $payload));
                $this->delete();

                return;
            }

            $handler = $this->resolveHandler($eventName);
            if ($handler === null) {
                Log::error("RabbitMQ: No handler registered for event: {$eventName}", $this->messageContext($eventName, $payload));
                $this->delete();

                return;
            }

            [$class, $method] = $handler;
            app($class)->{$method}($payload['data']);

            $this->delete();
            Log::debug('RabbitMQ: InboxConsumer completed', $this->messageContext($eventName, $payload));
        } catch (Throwable $e) {
            $this->handleProcessingException($eventName, $payload, $e);
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
     * 4. Берёт данные из поля `body` (или `data`) и нормализует их к массиву.
     *
     * @return array{id: string, displayName: string, data: array<string, mixed>}
     */
    public function payload(): array
    {
        $rawData = json_decode($this->getRawBody(), true) ?: [];
        $data = $rawData['body'] ?? $rawData['data'] ?? [];

        return [
            'id' => $rawData['id'] ?? Str::uuid()->toString(),
            'displayName' => $rawData['name'] ?? $rawData['displayName'] ?? '',
            'data' => is_array($data) ? $data : [],
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

    /**
     * Обрабатывает ошибку обработки сообщения.
     *
     * Шаги:
     * 1. Читает максимальное число попыток из конфигурации.
     * 2. Если лимит выключен — возвращает сообщение в очередь по старой логике.
     * 3. Если лимит достигнут — передаёт сообщение в poison-обработчик.
     * 4. Если лимит не достигнут — публикует сообщение повторно с задержкой.
     *
     * @param  array{id: string, displayName: string, data: array<string, mixed>}  $payload
     */
    private function handleProcessingException(?string $eventName, array $payload, Throwable $e): void
    {
        $maxAttempts = $this->maxAttempts();
        $attempts = $maxAttempts > 0 ? $this->attempts() : 0;

        if ($maxAttempts > 0 && $attempts >= $maxAttempts) {
            $this->handlePoisonMessage($eventName, $payload, $e, $attempts, $maxAttempts);

            return;
        }

        $this->releaseWithDelay($eventName, $payload, $e, $attempts, $maxAttempts);
    }

    /**
     * Возвращает сообщение в очередь с задержкой.
     *
     * Шаги:
     * 1. Читает задержку release из конфигурации.
     * 2. Логирует ошибку и текущую retry-стратегию.
     * 3. Вызывает release(), который ACK-ает текущее сообщение и публикует новое.
     * 4. Логирует ошибку release, если RabbitMQ отклонил операцию.
     *
     * @param  array{id: string, displayName: string, data: array<string, mixed>}  $payload
     */
    private function releaseWithDelay(?string $eventName, array $payload, Throwable $e, int $attempts, int $maxAttempts): void
    {
        $delay = (int) config('rabbit-transport.consumer.release_delay', 20);

        Log::error("RabbitMQ Consumer Error [{$eventName}]: ".$e->getMessage(), array_merge(
            $this->messageContext($eventName, $payload),
            [
                'attempts' => $attempts > 0 ? $attempts : null,
                'max_attempts' => $maxAttempts > 0 ? $maxAttempts : null,
                'release_delay' => $delay,
                'exception' => $e::class,
                'trace' => $e->getTraceAsString(),
            ],
        ));

        try {
            $this->release($delay);
        } catch (AMQPProtocolChannelException $releaseError) {
            Log::error("RabbitMQ Release Error [{$eventName}]: ".$releaseError->getMessage(), array_merge(
                $this->messageContext($eventName, $payload),
                [
                    'exception' => $releaseError::class,
                    'trace' => $releaseError->getTraceAsString(),
                ],
            ));
        }
    }

    /**
     * Обрабатывает poison-сообщение после исчерпания попыток.
     *
     * Шаги:
     * 1. Читает poison_action из конфигурации.
     * 2. Логирует критическое событие с безопасным контекстом сообщения.
     * 3. Для действия dead_letter/fail отклоняет сообщение через markAsFailed().
     * 4. Для действия delete подтверждает и удаляет сообщение из очереди.
     *
     * @param  array{id: string, displayName: string, data: array<string, mixed>}  $payload
     */
    private function handlePoisonMessage(?string $eventName, array $payload, Throwable $e, int $attempts, int $maxAttempts): void
    {
        $action = $this->poisonAction();
        $context = array_merge(
            $this->messageContext($eventName, $payload),
            [
                'attempts' => $attempts,
                'max_attempts' => $maxAttempts,
                'poison_action' => $action,
                'exception' => $e::class,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ],
        );

        Log::critical("RabbitMQ Poison Message [{$eventName}]: max attempts reached", $context);

        if ($action === 'dead_letter' || $action === 'fail') {
            $this->markAsFailed();

            return;
        }

        $this->delete();
    }

    /**
     * Возвращает максимальное число попыток обработки.
     *
     * Шаги:
     * 1. Читает rabbit-transport.consumer.max_attempts из конфигурации.
     * 2. Приводит значение к integer.
     * 3. Отсекает отрицательные значения, чтобы 0 означал отключённый лимит.
     */
    private function maxAttempts(): int
    {
        return max(0, (int) config('rabbit-transport.consumer.max_attempts', 0));
    }

    /**
     * Возвращает стратегию обработки poison-сообщения.
     *
     * Шаги:
     * 1. Читает rabbit-transport.consumer.poison_action из конфигурации.
     * 2. Нормализует значение к нижнему регистру.
     * 3. Разрешает только delete, dead_letter и fail.
     * 4. При неизвестном значении безопасно возвращает delete.
     */
    private function poisonAction(): string
    {
        $action = strtolower((string) config('rabbit-transport.consumer.poison_action', 'delete'));

        if (in_array($action, ['delete', 'dead_letter', 'fail'], true)) {
            return $action;
        }

        return 'delete';
    }

    /**
     * Формирует безопасный контекст сообщения для логов.
     *
     * Шаги:
     * 1. Берёт технический id сообщения.
     * 2. Берёт имя события из payload.
     * 3. Логирует только список ключей data, не полное содержимое.
     *
     * @param  array{id: string, displayName: string, data: array<string, mixed>}  $payload
     *
     * @return array{id: string, event: string|null, data_keys: array<int, string>}
     */
    private function messageContext(?string $eventName, array $payload): array
    {
        return [
            'id' => $payload['id'],
            'event' => $eventName,
            'data_keys' => array_keys($payload['data']),
        ];
    }
}
