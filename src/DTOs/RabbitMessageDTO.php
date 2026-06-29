<?php

declare(strict_types=1);

namespace PkmStudio\RabbitTransport\DTOs;

use JsonSerializable;

/**
 * DTO исходящего сообщения RabbitMQ.
 *
 * Намеренно не зависит от enum приложения: `name` — это логическое имя
 * события (например `AUDIT_RECORDED`), которое сериализуется в поле тела
 * сообщения `name` и по которому консьюмер диспетчеризует обработку
 * (токен (б) wire-контракта). Per-message routing key передаётся отдельно
 * в publisher (токен (а)).
 */
final readonly class RabbitMessageDTO implements JsonSerializable
{
    /**
     * @param  string  $name  Логическое имя события (попадёт в тело как `name`).
     * @param  array<string, mixed>  $data  Полезная нагрузка события.
     */
    public function __construct(
        public string $name,
        public array $data,
    ) {}

    /**
     * Сериализует сообщение в массив для отправки в очередь.
     *
     * Шаги:
     * 1. Помещает логическое имя события в поле `name`.
     * 2. Помещает полезную нагрузку в поле `data`.
     *
     * @return array{name: string, data: array<string, mixed>}
     */
    public function jsonSerialize(): array
    {
        return [
            'name' => $this->name,
            'data' => $this->data,
        ];
    }
}
