<?php

declare(strict_types=1);

use PkmStudio\RabbitTransport\Consumers\InboxConsumer;
use PkmStudio\RabbitTransport\Tests\Fixtures\HandlerSpy;

/**
 * Создаёт частичный мок InboxConsumer с подменёнными job-контекстными методами.
 *
 * @param  array<string, mixed>  $body  Тело сообщения (будет JSON-кодировано в getRawBody).
 * @return InboxConsumer&\Mockery\MockInterface
 */
function fakeConsumer(array $body): InboxConsumer
{
    $consumer = \Mockery::mock(InboxConsumer::class)->makePartial();
    $consumer->shouldReceive('getRawBody')->andReturn((string) json_encode($body));

    return $consumer;
}

test('известное событие диспетчеризуется на обработчик из реестра и удаляется', function (): void {
    $spy = new HandlerSpy();
    app()->instance(HandlerSpy::class, $spy);
    config(['rabbit-transport.inbound' => ['AUDIT_RECORDED' => [HandlerSpy::class, 'handle']]]);

    $consumer = fakeConsumer(['name' => 'AUDIT_RECORDED', 'body' => ['id' => 7, 'v' => 'значение']]);
    $consumer->shouldReceive('delete')->once();
    $consumer->shouldReceive('release')->never();

    $consumer->fire();

    expect($spy->received)->toBe(['id' => 7, 'v' => 'значение']);
});

test('неизвестное событие не вызывает обработчик и удаляется', function (): void {
    config(['rabbit-transport.inbound' => ['AUDIT_RECORDED' => [HandlerSpy::class, 'handle']]]);
    $spy = new HandlerSpy();
    app()->instance(HandlerSpy::class, $spy);

    $consumer = fakeConsumer(['name' => 'UNKNOWN_EVENT', 'body' => []]);
    $consumer->shouldReceive('delete')->once();
    $consumer->shouldReceive('release')->never();

    $consumer->fire();

    expect($spy->received)->toBeNull();
});

test('ошибка обработчика приводит к release с задержкой из конфига, без delete', function (): void {
    $spy = new HandlerSpy();
    $spy->shouldThrow = true;
    app()->instance(HandlerSpy::class, $spy);
    config([
        'rabbit-transport.inbound' => ['AUDIT_RECORDED' => [HandlerSpy::class, 'handle']],
        'rabbit-transport.consumer.release_delay' => 20,
    ]);

    $consumer = fakeConsumer(['name' => 'AUDIT_RECORDED', 'body' => ['id' => 1]]);
    $consumer->shouldReceive('release')->once()->with(20);
    $consumer->shouldReceive('delete')->never();

    $consumer->fire();

    expect(true)->toBeTrue();
});
