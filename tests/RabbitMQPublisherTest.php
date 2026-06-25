<?php

declare(strict_types=1);

use DanCenter\RabbitTransport\DTOs\RabbitMessageDTO;
use DanCenter\RabbitTransport\RabbitMQPublisher;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

/**
 * Поднимает мок connection/channel и captures destination, переданный в pushRaw.
 *
 * @param  bool  $pushThrows  Если true — pushRaw бросает исключение (ошибка публикации).
 * @return object{captured: array<int, string>}
 */
function fakeRabbitConnection(bool $pushThrows = false): object
{
    $captured = new class
    {
        /** @var array<int, string> */
        public array $destinations = [];
    };

    $channel = \Mockery::mock();
    $channel->shouldReceive('confirm_select')->andReturnNull();
    $channel->shouldReceive('wait_for_pending_acks_returns')->andReturnNull();

    $connection = \Mockery::mock();
    $connection->shouldReceive('getChannel')->andReturn($channel);

    $push = $connection->shouldReceive('pushRaw')->andReturnUsing(
        function (string $payload, string $destination) use ($captured, $pushThrows) {
            $captured->destinations[] = $destination;
            if ($pushThrows) {
                throw new RuntimeException('publish boom');
            }

            return null;
        }
    );

    Queue::shouldReceive('connection')->andReturn($connection);

    return $captured;
}

test('publish возвращает true при подтверждённой доставке', function (): void {
    fakeRabbitConnection();

    $result = (new RabbitMQPublisher())->publish(
        new RabbitMessageDTO('AUDIT_RECORDED', ['k' => 'v']),
        'crm.audit.audits.created',
    );

    expect($result)->toBeTrue();
});

test('publish возвращает false и логирует при ошибке pushRaw', function (): void {
    fakeRabbitConnection(pushThrows: true);
    Log::shouldReceive('error')->once();

    $result = (new RabbitMQPublisher())->publish(
        new RabbitMessageDTO('AUDIT_RECORDED', ['k' => 'v']),
        'crm.audit.audits.created',
    );

    expect($result)->toBeFalse();
});

test('явный per-message routing key используется как destination (токен а)', function (): void {
    $captured = fakeRabbitConnection();

    (new RabbitMQPublisher())->publish(
        new RabbitMessageDTO('AUDIT_RECORDED', []),
        'crm.audit.audits.created',
    );

    expect($captured->destinations)->toBe(['crm.audit.audits.created']);
});

test('при пустом routing key берётся дефолт из outbound-конфига', function (): void {
    config(['rabbit-transport.outbound' => ['STORES_UPSERTED' => 'crm.stores.upserted']]);
    $captured = fakeRabbitConnection();

    (new RabbitMQPublisher())->publish(new RabbitMessageDTO('STORES_UPSERTED', []));

    expect($captured->destinations)->toBe(['crm.stores.upserted']);
});

test('при отсутствии routing key и outbound-дефолта fallback на имя события + warning', function (): void {
    config(['rabbit-transport.outbound' => []]);
    Log::shouldReceive('warning')->once();
    $captured = fakeRabbitConnection();

    (new RabbitMQPublisher())->publish(new RabbitMessageDTO('UNMAPPED_EVENT', []));

    expect($captured->destinations)->toBe(['UNMAPPED_EVENT']);
});
