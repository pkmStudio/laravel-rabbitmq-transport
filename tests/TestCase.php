<?php

declare(strict_types=1);

namespace PkmStudio\RabbitTransport\Tests;

use PkmStudio\RabbitTransport\RabbitTransportServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

/**
 * Базовый TestCase пакета на основе Orchestra Testbench.
 *
 * Регистрирует сервис-провайдер пакета в тестовом приложении Laravel.
 */
abstract class TestCase extends Orchestra
{
    /**
     * Возвращает провайдеры пакета для тестового приложения.
     *
     * Шаги:
     * 1. Регистрирует RabbitTransportServiceProvider.
     *
     * @param  \Illuminate\Foundation\Application  $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            RabbitTransportServiceProvider::class,
        ];
    }
}
