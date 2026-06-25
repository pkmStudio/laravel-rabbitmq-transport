# dan-center/rabbit-transport

Generic AMQP/RabbitMQ transport for Laravel, extracted from the dan-center monolith.

Provides (filled in across T1.2–T1.4):

- **Publisher** with publisher confirms (`publish(): bool`).
- **Inbox consumer** with a config-driven `event-name → [Class, Method]` registry — the package never references application classes.
- **Optional Horizon worker** — registered only when `laravel/horizon` is installed; vanilla consumers use the standard `vladimir-yuldashev/laravel-queue-rabbitmq` worker.
- **Setup command** that declares exchange/queue/bindings from config.

PSR-4 namespace: `DanCenter\RabbitTransport\`.

## Install (local path repository)

In the consuming app's `composer.json`:

```json
{
    "repositories": [
        { "type": "path", "url": "../packages/rabbit-transport" }
    ],
    "require": {
        "dan-center/rabbit-transport": "*"
    }
}
```

## Configure

```bash
php artisan vendor:publish --tag=rabbit-transport-config
```

Then declare the inbound registry, outbound events, and setup topology in
`config/rabbit-transport.php`.

## Wire contract (two independent tokens)

The decoupling from application enums preserves both tokens exactly:

| Token | Where it lives | Example | Used for |
|-------|----------------|---------|----------|
| (a) routing key | `publish()` arg, or `outbound` default | `crm.audit.{table}.{event}` | AMQP topic routing |
| (b) body `name` | `RabbitMessageDTO->name` | `AUDIT_RECORDED` | consumer dispatch via `inbound` registry |

The event **name** (`AUDIT_RECORDED`, token b) is **not** the routing key
(`crm.audit.recorded`, token a). In the former `OutboundEventsEnum` these were
`enum->name` and `enum->value`; here they are split explicitly.

### Consuming app config example

```php
// config/rabbit-transport.php
return [
    'connection' => 'rabbitmq_inbox',

    // token (b): body `name` → handler
    'inbound' => [
        'AUDIT_RECORDED' => [\App\Services\Audit\AuditInboxService::class, 'upsert'],
    ],

    // token (a) default: logical name → routing key (per-message arg wins)
    'outbound' => [
        'AUDIT_RECORDED' => 'crm.audit.recorded',
    ],

    'setup' => [
        'exchange' => 'application.events',
        'exchange_type' => 'topic',
        'queue' => 'crm.inbox',
        'bindings' => ['crm.inbox', 'crm.audit.#'],
    ],
];
```

### Publishing

```php
$publisher->publish(
    new RabbitMessageDTO(name: 'AUDIT_RECORDED', data: $payload),
    routingKey: "crm.audit.{$table}.{$event}", // token (a), per-message
);
```
