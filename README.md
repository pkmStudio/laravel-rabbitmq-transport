# dan-center/rabbit-transport

> Laravel package that adds a small, opinionated microservice transport layer on top of `vladimir-yuldashev/laravel-queue-rabbitmq`.

`dan-center/rabbit-transport` keeps the excellent RabbitMQ queue driver from `vladimir-yuldashev/laravel-queue-rabbitmq`, but adds the missing application-level pieces for service-to-service communication:

- a publisher with RabbitMQ publisher confirms;
- a stable wire DTO: `name + data`;
- a config-driven inbound handler registry;
- a setup command for exchange, queue and binding masks;
- retry and poison-message policy for consumers;
- no dependency on `App\...` classes inside the package.

The package is useful when several Laravel services communicate through RabbitMQ topic exchanges and you want the routing contract to live in config instead of hardcoded enums or application classes.

## Why this package

`vladimir-yuldashev/laravel-queue-rabbitmq` gives Laravel a RabbitMQ queue driver. This package builds on that driver and standardizes how services publish and consume domain events.

| Problem | What this package adds |
|---|---|
| Services need different handlers for the same queue driver | `rabbit-transport.inbound`: event name to `[Class, method]`. |
| Routing keys and event names get mixed | Two explicit tokens: AMQP routing key and body `name`. |
| Publisher must know if RabbitMQ accepted the message | `RabbitMQPublisher::publish(): bool` with publisher confirms. |
| Each service needs its own queue/bindings | `rabbit-transport:setup` reads exchange, queue and masks from config. |
| Bad messages can loop forever | Configurable `max_attempts` and `poison_action`. |

## Requirements

- PHP `^8.4`
- Laravel components `^12.0`
- `vladimir-yuldashev/laravel-queue-rabbitmq ^14.5 || ^15.0`
- `php-amqplib/php-amqplib ^3.0`

## Installation

When the package is published to Packagist:

```bash
composer require dan-center/rabbit-transport:^1.0
```

Before Packagist or before the first stable tag, add the GitHub repository as a Composer VCS repository in the consuming Laravel app:

```json
{
  "repositories": [
    {
      "type": "vcs",
      "url": "https://github.com/pkmStudio/laravel-rabbitmq-transport"
    }
  ],
  "require": {
    "dan-center/rabbit-transport": "dev-master"
  }
}
```

For local development with a sibling package:

```json
{
  "repositories": [
    {
      "type": "path",
      "url": "../packages/rabbit-transport",
      "options": { "symlink": true }
    }
  ],
  "require": {
    "dan-center/rabbit-transport": "@dev"
  }
}
```

Publish the config:

```bash
php artisan vendor:publish --tag=rabbit-transport-config
```

## Queue connection

Configure a RabbitMQ queue connection in `config/queue.php`. The important part is the custom job class:

```php
use DanCenter\RabbitTransport\Consumers\InboxConsumer;

'connections' => [
    'rabbitmq_inbox' => [
        'driver' => 'rabbitmq',
        'queue' => env('RABBIT_TRANSPORT_QUEUE', 'crm.inbox'),
        'hosts' => [
            [
                'host' => env('RABBITMQ_HOST', '127.0.0.1'),
                'port' => env('RABBITMQ_PORT', 5672),
                'user' => env('RABBITMQ_USER', 'guest'),
                'password' => env('RABBITMQ_PASSWORD', 'guest'),
                'vhost' => env('RABBITMQ_VHOST', '/'),
            ],
        ],
        'options' => [
            'queue' => [
                'exchange' => env('RABBIT_TRANSPORT_EXCHANGE', 'application.events'),
                'exchange_type' => env('RABBIT_TRANSPORT_EXCHANGE_TYPE', 'topic'),
                'exchange_routing_key' => '',
                'declare' => true,
                'job' => InboxConsumer::class,
                'prefetch_count' => (int) env('RABBITMQ_PREFETCH_COUNT', 10),
            ],
        ],
        'worker' => env('RABBITMQ_WORKER', 'default'),
    ],
],
```

Then point the package to that connection:

```env
RABBIT_TRANSPORT_CONNECTION=rabbitmq_inbox
```

## Configuration

`config/rabbit-transport.php` has four important sections:

```php
return [
    'connection' => env('RABBIT_TRANSPORT_CONNECTION', 'rabbitmq_inbox'),

    'publish_confirm_timeout' => (float) env('RABBIT_TRANSPORT_CONFIRM_TIMEOUT', 5.0),

    'consumer' => [
        'release_delay' => (int) env('RABBIT_TRANSPORT_RELEASE_DELAY', 20),
        'max_attempts' => (int) env('RABBIT_TRANSPORT_MAX_ATTEMPTS', 0),
        'poison_action' => env('RABBIT_TRANSPORT_POISON_ACTION', 'delete'),
    ],

    'inbound' => [
        'AUDIT_RECORDED' => [App\Rabbit\Handlers\AuditRecordedHandler::class, 'handle'],
    ],

    'outbound' => [
        'AUDIT_RECORDED' => 'crm.audit.recorded',
    ],

    'setup' => [
        'exchange' => env('RABBIT_TRANSPORT_EXCHANGE', 'application.events'),
        'exchange_type' => env('RABBIT_TRANSPORT_EXCHANGE_TYPE', 'topic'),
        'queue' => env('RABBIT_TRANSPORT_QUEUE', 'crm.inbox'),
        'bindings' => [
            'crm.audit.#',
            'billing.#',
        ],
    ],
];
```

## Wire contract

The package separates two tokens that are often confused:

| Token | Where it lives | Example | Purpose |
|---|---|---|---|
| Routing key | AMQP publish argument | `crm.audit.orders.updated` | Topic exchange routing. |
| Event name | JSON body field `name` | `AUDIT_RECORDED` | Consumer handler dispatch. |

The consumer dispatches only by body `name`. RabbitMQ routes only by routing key.

```json
{
  "name": "AUDIT_RECORDED",
  "data": {
    "audit_id": "123",
    "event": "updated"
  }
}
```

The same message can be published with a specific routing key:

```php
use DanCenter\RabbitTransport\DTOs\RabbitMessageDTO;
use DanCenter\RabbitTransport\RabbitMQPublisher;

app(RabbitMQPublisher::class)->publish(
    new RabbitMessageDTO(
        name: 'AUDIT_RECORDED',
        data: [
            'audit_id' => '123',
            'event' => 'updated',
        ],
    ),
    routingKey: 'crm.audit.orders.updated',
);
```

If `routingKey` is omitted, `RabbitMQPublisher` uses `rabbit-transport.outbound[$message->name]`. If that is also missing, it falls back to the event name and writes a warning log.

## Declaring handlers

Handlers are declared by the consuming application, not by the package:

```php
'inbound' => [
    'ORDER_CREATED' => [App\Services\Orders\OrderCreatedConsumer::class, 'handle'],
    'AUDIT_RECORDED' => [DanCenter\Audit\Services\AuditInboxService::class, 'upsert'],
],
```

The handler method receives the message `data` array:

```php
final class OrderCreatedConsumer
{
    public function handle(array $data): void
    {
        // validate DTO, update local read model, dispatch job, etc.
    }
}
```

Unknown events are logged and deleted. Handler exceptions are handled by the retry and poison policy.

## Declaring binding masks

Each service owns its queue and binding masks:

```php
'setup' => [
    'exchange' => 'application.events',
    'exchange_type' => 'topic',
    'queue' => 'auditor.audit',
    'bindings' => [
        'crm.audit.#',
    ],
],
```

Run setup after changing queue or bindings:

```bash
php artisan rabbit-transport:setup
```

The command declares a durable exchange, durable queue and all configured bindings.

## Running the consumer

```bash
php artisan queue:work rabbitmq_inbox --queue=auditor.audit --sleep=1 --tries=3 --timeout=90 --verbose
```

For a Docker service, run the same command as the worker container command.

## Laravel Horizon

If a service uses Laravel Horizon, the queue connection can use the package Horizon-aware worker:

```php
use DanCenter\RabbitTransport\Consumers\InboxConsumer;
use DanCenter\RabbitTransport\Workers\CustomRabbitMQQueue;

'connections' => [
    'rabbitmq_inbox' => [
        'driver' => 'rabbitmq',
        'queue' => env('RABBIT_TRANSPORT_QUEUE', 'vehicles.inbox'),
        'hosts' => [
            [
                'host' => env('RABBITMQ_HOST', '127.0.0.1'),
                'port' => env('RABBITMQ_PORT', 5672),
                'user' => env('RABBITMQ_USER', 'guest'),
                'password' => env('RABBITMQ_PASSWORD', 'guest'),
                'vhost' => env('RABBITMQ_VHOST', '/'),
            ],
        ],
        'options' => [
            'queue' => [
                'exchange' => env('RABBIT_TRANSPORT_EXCHANGE', 'application.events'),
                'exchange_type' => env('RABBIT_TRANSPORT_EXCHANGE_TYPE', 'topic'),
                'exchange_routing_key' => '',
                'declare' => true,
                'job' => InboxConsumer::class,
                'prefetch_count' => (int) env('RABBITMQ_PREFETCH_COUNT', 10),
            ],
        ],
        'worker' => CustomRabbitMQQueue::class,
    ],
],
```

Then add a Horizon supervisor for the inbox queue:

```php
'supervisor-inbox' => [
    'connection' => 'rabbitmq_inbox',
    'queue' => [env('RABBIT_TRANSPORT_QUEUE', 'vehicles.inbox')],
    'balance' => 'auto',
    'autoScalingStrategy' => 'time',
    'maxProcesses' => (int) env('HORIZON_INBOX_MAX_PROCESSES', 1),
    'tries' => 3,
    'timeout' => 90,
],
```

`CustomRabbitMQQueue` suppresses duplicate Horizon queue events for this driver. Services without Horizon should keep the default worker:

```php
'worker' => env('RABBITMQ_WORKER', 'default'),
```

## Application-level publisher service

Keep domain/application code behind your own service or port, and inject `RabbitMQPublisher` only at the infrastructure edge.

```php
<?php

declare(strict_types=1);

namespace App\Notifications;

use DanCenter\RabbitTransport\DTOs\RabbitMessageDTO;
use DanCenter\RabbitTransport\RabbitMQPublisher;

final readonly class RabbitMqFileNotificationService
{
    public function __construct(
        private RabbitMQPublisher $publisher,
    ) {}

    public function send(int $userId, string $path, int $filesCount = 1): bool
    {
        return $this->publisher->publish(
            new RabbitMessageDTO(
                name: 'FILE_EXPORTED',
                data: [
                    'user_id' => $userId,
                    'path' => $path,
                    'files_count' => $filesCount,
                ],
            ),
            routingKey: 'vehicles.file.exported',
        );
    }
}
```

The receiving service declares the handler and binding mask:

```php
'inbound' => [
    'FILE_EXPORTED' => [App\Rabbit\Handlers\FileExportedHandler::class, 'handle'],
],

'setup' => [
    'exchange' => 'application.events',
    'exchange_type' => 'topic',
    'queue' => 'filament.notifications',
    'bindings' => [
        'vehicles.file.#',
    ],
],
```

## Retry and poison messages

Default behavior is backwards-compatible: `max_attempts=0` means infinite retry through `release($delay)`.

For services that must not loop on invalid payloads:

```env
RABBIT_TRANSPORT_RELEASE_DELAY=20
RABBIT_TRANSPORT_MAX_ATTEMPTS=3
RABBIT_TRANSPORT_POISON_ACTION=delete
```

Supported `poison_action` values:

| Value | Behavior |
|---|---|
| `delete` | Log `CRITICAL`, ACK and remove the message. |
| `dead_letter` | Mark the message as failed; use queue-level DLX if configured. |
| `fail` | Alias for `dead_letter`. |

## Testing

```bash
composer install
vendor/bin/pest
```

Current test coverage includes:

- DTO serialization;
- publisher confirms success/failure;
- explicit routing key vs outbound fallback;
- inbound dispatch by `name`;
- unknown event handling;
- retry on handler exception.

## Versioning

Use semantic versioning for public repositories:

```bash
git tag v1.0.0
git push origin v1.0.0
```

Consuming apps can then run:

```bash
composer update dan-center/rabbit-transport
```

## License

The package is currently marked as `proprietary` in `composer.json`. Change the license before publishing as open source.
