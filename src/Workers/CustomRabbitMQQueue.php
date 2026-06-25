<?php

declare(strict_types=1);

namespace DanCenter\RabbitTransport\Workers;

use VladimirYuldashev\LaravelQueueRabbitMQ\Horizon\RabbitMQQueue;

/**
 * Horizon-aware очередь RabbitMQ.
 *
 * Используется только в приложениях с установленным Horizon — выбирается
 * через `worker` соответствующего connection в config/queue.php. Потребители
 * без Horizon (например auditor) используют ванильный worker ('default') и
 * этот класс не загружают. Пакет не требует laravel/horizon в composer.
 *
 * Класс грузится безопасно даже без Horizon: родитель extends ванильного
 * BaseRabbitMQQueue, а Horizon-классы затрагиваются только внутри методов.
 */
final class CustomRabbitMQQueue extends RabbitMQQueue
{
    /**
     * Подавляет отправку событий в Horizon, чтобы избежать конфликтов
     * со стандартной обработкой очереди.
     *
     * Шаги:
     * 1. Намеренно ничего не делает (no-op).
     *
     * @param  string  $queue
     * @param  mixed  $event
     */
    protected function event($queue, $event): void {}
}
