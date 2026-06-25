<?php

declare(strict_types=1);

namespace DanCenter\RabbitTransport\Tests\Fixtures;

use RuntimeException;

/**
 * Тестовый обработчик входящих событий для проверки реестра диспетчеризации.
 */
class HandlerSpy
{
    /** @var array<string, mixed>|null Данные последнего вызова. */
    public ?array $received = null;

    public bool $shouldThrow = false;

    /**
     * Принимает данные события и сохраняет их (или бросает исключение).
     *
     * Шаги:
     * 1. При флаге shouldThrow — бросает RuntimeException (эмуляция ошибки обработки).
     * 2. Иначе сохраняет полученные данные в received.
     *
     * @param  array<string, mixed>  $data
     */
    public function handle(array $data): void
    {
        if ($this->shouldThrow) {
            throw new RuntimeException('handler failure');
        }

        $this->received = $data;
    }
}
