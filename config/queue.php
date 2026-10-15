<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Default Queue Connection Name
    |--------------------------------------------------------------------------
    |
    | Laravel's queue supports a variety of backends via a single, unified
    | API, giving you convenient access to each backend using identical
    | syntax for each. The default queue connection is defined below.
    |
    */

    'default' => env('QUEUE_CONNECTION', 'database'),

    /*
    |--------------------------------------------------------------------------
    | Queue Connections
    |--------------------------------------------------------------------------
    |
    | Here you may configure the connection options for every queue backend
    | used by your application. An example configuration is provided for
    | each backend supported by Laravel. You're also free to add more.
    |
    | Drivers: "sync", "database", "beanstalkd", "sqs", "redis",
    |          "deferred", "background", "failover", "null"
    |
    */

    'connections' => [

        'sync' => [
            'driver' => 'sync',
        ],

        'database' => [
            'driver' => 'database',
            'connection' => env('DB_QUEUE_CONNECTION'),
            'table' => env('DB_QUEUE_TABLE', 'jobs'),
            'queue' => env('DB_QUEUE', 'default'),
            'retry_after' => (int) env('DB_QUEUE_RETRY_AFTER', 90),
            'after_commit' => false,
        ],

        'beanstalkd' => [
            'driver' => 'beanstalkd',
            'host' => env('BEANSTALKD_QUEUE_HOST', 'localhost'),
            'queue' => env('BEANSTALKD_QUEUE', 'default'),
            'retry_after' => (int) env('BEANSTALKD_QUEUE_RETRY_AFTER', 90),
            'block_for' => 0,
            'after_commit' => false,
        ],

        'sqs' => [
            'driver' => 'sqs',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'prefix' => env('SQS_PREFIX', 'https://sqs.us-east-1.amazonaws.com/your-account-id'),
            'queue' => env('SQS_QUEUE', 'default'),
            'suffix' => env('SQS_SUFFIX'),
            'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
            'after_commit' => false,
        ],

        /*
        | Conexión Redis/Valkey por defecto. En la plataforma todo I/O externo
        | (SAP, FCM, email, SMS, transportistas) sale por el outbox y nunca por
        | HTTP síncrono dentro de un request (ADR 002), así que las colas son el
        | corazón del sistema asíncrono. Usamos cuatro colas nombradas sobre la
        | MISMA conexión Valkey para poder darle a cada flujo su propio worker,
        | prioridad y recorte de reintentos (ver .docker/supervisor y docs/deploy.md):
        |
        |   - default        trabajo general del dominio
        |   - outbox         relay del outbox hacia destinos externos (lento, I/O)
        |   - notifications  envío de notificaciones al cliente (FCM/email/SMS)
        |   - planning        generación de planes diarios de ruteo (CPU, pesado)
        |
        | `retry_after` debe ser SIEMPRE mayor que el `--timeout` del worker de esa
        | cola, si no Laravel liberaría un job que todavía se está procesando.
        */
        'redis' => [
            'driver' => 'redis',
            'connection' => env('REDIS_QUEUE_CONNECTION', 'default'),
            'queue' => env('REDIS_QUEUE', 'default'),
            'retry_after' => (int) env('REDIS_QUEUE_RETRY_AFTER', 120),
            'block_for' => null,
            'after_commit' => false,
        ],

        // Relay del outbox: cada mensaje habla con un sistema externo, así que el
        // procesamiento es lento. Reintentos holgados; el outbox es idempotente
        // por event_id (ADR 002), por lo que reintentar es seguro.
        'outbox' => [
            'driver' => 'redis',
            'connection' => env('REDIS_QUEUE_CONNECTION', 'default'),
            'queue' => env('REDIS_OUTBOX_QUEUE', 'outbox'),
            'retry_after' => (int) env('REDIS_OUTBOX_RETRY_AFTER', 660),
            'block_for' => null,
            'after_commit' => false,
        ],

        // Notificaciones al cliente (ADR 006): ráfagas cortas, muchos mensajes.
        'notifications' => [
            'driver' => 'redis',
            'connection' => env('REDIS_QUEUE_CONNECTION', 'default'),
            'queue' => env('REDIS_NOTIFICATIONS_QUEUE', 'notifications'),
            'retry_after' => (int) env('REDIS_NOTIFICATIONS_RETRY_AFTER', 180),
            'block_for' => null,
            'after_commit' => false,
        ],

        // Planificación diaria de ruteo: jobs pesados de CPU y de larga duración.
        'planning' => [
            'driver' => 'redis',
            'connection' => env('REDIS_QUEUE_CONNECTION', 'default'),
            'queue' => env('REDIS_PLANNING_QUEUE', 'planning'),
            'retry_after' => (int) env('REDIS_PLANNING_RETRY_AFTER', 1260),
            'block_for' => null,
            'after_commit' => false,
        ],

        'deferred' => [
            'driver' => 'deferred',
        ],

        'background' => [
            'driver' => 'background',
        ],

        'failover' => [
            'driver' => 'failover',
            'connections' => [
                'database',
                'deferred',
            ],
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Job Batching
    |--------------------------------------------------------------------------
    |
    | The following options configure the database and table that store job
    | batching information. These options can be updated to any database
    | connection and table which has been defined by your application.
    |
    */

    'batching' => [
        'database' => env('DB_CONNECTION', 'sqlite'),
        'table' => 'job_batches',
    ],

    /*
    |--------------------------------------------------------------------------
    | Failed Queue Jobs
    |--------------------------------------------------------------------------
    |
    | These options configure the behavior of failed queue job logging so you
    | can control how and where failed jobs are stored. Laravel ships with
    | support for storing failed jobs in a simple file or in a database.
    |
    | Supported drivers: "database-uuids", "dynamodb", "file", "null"
    |
    */

    'failed' => [
        'driver' => env('QUEUE_FAILED_DRIVER', 'database-uuids'),
        'database' => env('DB_CONNECTION', 'sqlite'),
        'table' => 'failed_jobs',
    ],

];
