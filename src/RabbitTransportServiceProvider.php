<?php

declare(strict_types=1);

namespace DanCenter\RabbitTransport;

use DanCenter\RabbitTransport\Console\RabbitMqSetupCommand;
use Illuminate\Support\ServiceProvider;

/**
 * Сервис-провайдер пакета rabbit-transport.
 *
 * Регистрирует конфигурацию транспортного слоя AMQP и (по мере переноса)
 * publisher, inbox-consumer, опциональный Horizon-worker и setup-команду.
 * Пакет не зависит от классов приложения — все привязки задаёт приложение
 * через опубликованный config/rabbit-transport.php.
 */
final class RabbitTransportServiceProvider extends ServiceProvider
{
    /**
     * Регистрирует сервисы пакета в контейнере.
     *
     * Шаги:
     * 1. Сливает дефолтную конфигурацию пакета с конфигом приложения.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/rabbit-transport.php',
            'rabbit-transport',
        );
    }

    /**
     * Выполняет загрузочные действия пакета.
     *
     * Шаги:
     * 1. Публикует config-stub для переопределения приложением.
     * 2. Регистрирует console-команду настройки топологии RabbitMQ.
     */
    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/rabbit-transport.php' => $this->app->configPath('rabbit-transport.php'),
        ], 'rabbit-transport-config');

        if ($this->app->runningInConsole()) {
            $this->commands([
                RabbitMqSetupCommand::class,
            ]);
        }
    }
}
