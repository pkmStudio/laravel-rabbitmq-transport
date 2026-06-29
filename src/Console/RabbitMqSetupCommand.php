<?php

declare(strict_types=1);

namespace PkmStudio\RabbitTransport\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Queue;
use PhpAmqpLib\Channel\AMQPChannel;

/**
 * Команда настройки топологии RabbitMQ (exchange/queue/bindings).
 *
 * Все параметры берутся из config-секции `rabbit-transport.setup`,
 * поэтому одна и та же команда работает в любом приложении (CRM, auditor),
 * а конкретные routing-маски (например `crm.audit.#`) объявляются на стороне
 * приложения. Пакет не хардкодит классы/маски приложения.
 */
final class RabbitMqSetupCommand extends Command
{
    /**
     * Сигнатура команды.
     *
     * @var string
     */
    protected $signature = 'rabbit-transport:setup';

    /**
     * Описание команды.
     *
     * @var string
     */
    protected $description = 'Настраивает RabbitMQ exchange, queue и bindings из config rabbit-transport.setup';

    /**
     * Настраивает RabbitMQ exchange, queue и bindings для входящих событий.
     *
     * Шаги:
     * 1. Читает connection и топологию из config rabbit-transport.
     * 2. Получает нативный канал AMQP.
     * 3. Объявляет durable topic exchange.
     * 4. Объявляет durable queue.
     * 5. Связывает queue с exchange по каждой routing-маске из config.
     */
    public function handle(): int
    {
        $connectionName = (string) config('rabbit-transport.connection', 'rabbitmq_inbox');
        $exchangeName = (string) config('rabbit-transport.setup.exchange', 'application.events');
        $exchangeType = (string) config('rabbit-transport.setup.exchange_type', 'topic');
        $queueName = (string) config('rabbit-transport.setup.queue', 'crm.inbox');
        $bindings = (array) config('rabbit-transport.setup.bindings', []);

        $connection = Queue::connection($connectionName);
        /** @var AMQPChannel $channel */
        $channel = $connection->getChannel();

        $channel->exchange_declare($exchangeName, $exchangeType, false, true, false);
        $this->info("Exchange [{$exchangeName}] проверен/создан.");

        $channel->queue_declare($queueName, false, true, false, false);
        $this->info("Очередь [{$queueName}] проверена/создана.");

        if ($bindings === []) {
            $this->warn('rabbit-transport.setup.bindings пуст — bindings не созданы.');

            return self::SUCCESS;
        }

        foreach ($bindings as $routingKeyMask) {
            $channel->queue_bind($queueName, $exchangeName, (string) $routingKeyMask);
        }
        $this->info("Связь [{$exchangeName}] -> [{$queueName}] установлена по ".count($bindings)." маск(ам).");

        return self::SUCCESS;
    }
}
