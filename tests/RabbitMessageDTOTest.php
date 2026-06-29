<?php

declare(strict_types=1);

use PkmStudio\RabbitTransport\DTOs\RabbitMessageDTO;

test('DTO сериализует логическое имя события в поле name (токен б wire-контракта)', function (): void {
    $dto = new RabbitMessageDTO(name: 'AUDIT_RECORDED', data: ['k' => 'значение']);

    $encoded = json_encode($dto, JSON_UNESCAPED_UNICODE);

    expect($dto->jsonSerialize())->toBe([
        'name' => 'AUDIT_RECORDED',
        'data' => ['k' => 'значение'],
    ])
        ->and($encoded)->toContain('"name":"AUDIT_RECORDED"')
        ->and($encoded)->toContain('значение')
        ->and($encoded)->not->toContain('\u04');
});
