<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| RabbitTransport — конфигурация транспортного слоя AMQP
|--------------------------------------------------------------------------
|
| Пакет НЕ ссылается на классы приложения (App\...). Все привязки
| «событие → обработчик», исходящие события и топология exchange/queue
| объявляются приложением здесь (публикуется как config/rabbit-transport.php).
|
| Заполняется по мере переноса (T1.2–T1.4):
|  - connection  — имя queue-connection из config/queue.php (T1.2);
|  - inbound     — реестр входящих событий event-name → [Class, Method] (T1.3);
|  - outbound    — исходящие события name → routing key (T1.3);
|  - setup       — топология exchange/queue/bindings для setup-команды (T1.4).
|
*/

return [

    /*
    | Имя queue-connection (config/queue.php), через которое работает
    | publisher и inbox-consumer. Историческое имя в dan-center — rabbitmq_inbox.
    */
    'connection' => env('RABBIT_TRANSPORT_CONNECTION', 'rabbitmq_inbox'),

    /*
    | Сколько секунд ждать подтверждения publisher confirms перед таймаутом.
    */
    'publish_confirm_timeout' => (float) env('RABBIT_TRANSPORT_CONFIRM_TIMEOUT', 5.0),

    /*
    | Настройки консьюмера. release_delay — задержка (сек) перед повторной
    | доставкой при ошибке обработки. Стратегию poison-сообщений
    | (max-attempts/dead-letter) задаёт сервис-консьюмер (см. T3.4b).
    */
    'consumer' => [
        'release_delay' => (int) env('RABBIT_TRANSPORT_RELEASE_DELAY', 20),
    ],

    /*
    | Реестр входящих событий (T1.3): имя события из тела сообщения (поле `name`)
    | → обработчик [class-string, method]. Консьюмер диспетчеризует по этому ключу.
    |
    | Пример (объявляется приложением):
    |   'AUDIT_RECORDED' => [\App\Services\Audit\AuditInboxService::class, 'upsert'],
    */
    'inbound' => [],

    /*
    | Исходящие события (T1.3): логическое имя → routing key по умолчанию.
    | Per-message routing key может переопределяться отправителем.
    |
    | Пример:
    |   'AUDIT_RECORDED' => 'crm.audit.recorded',
    */
    'outbound' => [],

    /*
    | Топология для setup-команды (T1.4): exchange, очередь и routing-маски.
    | Маски (например 'crm.audit.#') объявляются на стороне приложения.
    */
    'setup' => [
        'exchange' => env('RABBIT_TRANSPORT_EXCHANGE', 'application.events'),
        'exchange_type' => env('RABBIT_TRANSPORT_EXCHANGE_TYPE', 'topic'),
        'queue' => env('RABBIT_TRANSPORT_QUEUE', 'crm.inbox'),
        'bindings' => [],
    ],

];
