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
`config/rabbit-transport.php`. The wire contract preserves two tokens:

- per-message **routing key** (e.g. `crm.audit.{table}.{event}`), set by the sender;
- the message body field **`name`** (e.g. `AUDIT_RECORDED`), used by the consumer to dispatch.
