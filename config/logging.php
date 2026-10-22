<?php

declare(strict_types=1);

use App\Http\Middleware\RequestId;
use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\NullHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\SyslogUdpHandler;
use Monolog\Processor\PsrLogMessageProcessor;

return [

    /*
    |--------------------------------------------------------------------------
    | Default Log Channel
    |--------------------------------------------------------------------------
    |
    | This option defines the default log channel that is utilized to write
    | messages to your logs. The value provided here should match one of
    | the channels present in the list of "channels" configured below.
    |
    */

    'default' => env('LOG_CHANNEL', 'stack'),

    /*
    |--------------------------------------------------------------------------
    | Deprecations Log Channel
    |--------------------------------------------------------------------------
    |
    | This option controls the log channel that should be used to log warnings
    | regarding deprecated PHP and library features. This allows you to get
    | your application ready for upcoming major versions of dependencies.
    |
    */

    'deprecations' => [
        'channel' => env('LOG_DEPRECATIONS_CHANNEL', 'null'),
        'trace' => env('LOG_DEPRECATIONS_TRACE', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Log Channels
    |--------------------------------------------------------------------------
    |
    | Here you may configure the log channels for your application. Laravel
    | utilizes the Monolog PHP logging library, which includes a variety
    | of powerful log handlers and formatters that you're free to use.
    |
    | Available drivers: "single", "daily", "monthly", "slack", "syslog",
    |                    "errorlog", "monolog", "custom", "stack"
    |
    */

    'channels' => [

        'stack' => [
            'driver' => 'stack',
            'channels' => explode(',', (string) env('LOG_STACK', 'single')),
            'ignore_exceptions' => false,
        ],

        'single' => [
            'driver' => 'single',
            'path' => storage_path('logs/laravel.log'),
            'level' => env('LOG_LEVEL', 'debug'),
            'replace_placeholders' => true,
        ],

        'daily' => [
            'driver' => 'daily',
            'path' => storage_path('logs/laravel.log'),
            'level' => env('LOG_LEVEL', 'debug'),
            'max_files' => env('LOG_DAILY_DAYS', 14),
            'replace_placeholders' => true,
        ],

        'monthly' => [
            'driver' => 'monthly',
            'path' => storage_path('logs/laravel.log'),
            'level' => env('LOG_LEVEL', 'debug'),
            'max_files' => 3,
            'replace_placeholders' => true,
        ],

        'slack' => [
            'driver' => 'slack',
            'url' => env('LOG_SLACK_WEBHOOK_URL'),
            'username' => env('LOG_SLACK_USERNAME', env('APP_NAME', 'Laravel')),
            'emoji' => env('LOG_SLACK_EMOJI', ':boom:'),
            'level' => env('LOG_LEVEL', 'critical'),
            'replace_placeholders' => true,
        ],

        'papertrail' => [
            'driver' => 'monolog',
            'level' => env('LOG_LEVEL', 'debug'),
            'handler' => env('LOG_PAPERTRAIL_HANDLER', SyslogUdpHandler::class),
            'handler_with' => [
                'host' => env('PAPERTRAIL_URL'),
                'port' => env('PAPERTRAIL_PORT'),
                'connectionString' => 'tls://'.env('PAPERTRAIL_URL').':'.env('PAPERTRAIL_PORT'),
            ],
            'processors' => [PsrLogMessageProcessor::class],
        ],

        'stderr' => [
            'driver' => 'monolog',
            'level' => env('LOG_LEVEL', 'debug'),
            'handler' => StreamHandler::class,
            'handler_with' => [
                'stream' => 'php://stderr',
            ],
            'formatter' => env('LOG_STDERR_FORMATTER'),
            'processors' => [PsrLogMessageProcessor::class],
        ],

        /*
        | Canal estructurado en JSON (una línea por evento) pensado para que el
        | agregador de logs (Loki, CloudWatch, ELK, etc.) lo ingiera sin parsear
        | texto libre. Cada registro lleva: message, level, channel, datetime,
        | context y extra. Ver docs/observability.md.
        |
        | Correlación: el processor de `request_id` es CONFIGURABLE vía
        | LOG_REQUEST_ID_PROCESSOR. Por defecto usa UidProcessor de Monolog, que
        | adjunta un id único por proceso bajo `extra.uid`, suficiente para
        | correlacionar todas las líneas de un mismo request/worker. Para
        | propagar un id de extremo a extremo (p. ej. tomar el header
        | X-Request-Id o reusar el id del evento de outbox), apuntar esta env a
        | un processor propio; esa clase la aporta la capa de aplicación, no esta
        | config. La cadena asíncrona (evento → outbox → relay → notificación)
        | se traza arrastrando ese mismo id en el `context`; ver el doc.
        */
        'json' => [
            'driver' => 'monolog',
            'level' => env('LOG_LEVEL', 'debug'),
            'handler' => StreamHandler::class,
            'handler_with' => [
                'stream' => env('LOG_JSON_STREAM', 'php://stderr'),
            ],
            'formatter' => JsonFormatter::class,
            'formatter_with' => [
                // Una línea JSON por registro; incluir stacktraces de excepciones.
                'appendNewline' => true,
                'includeStacktraces' => true,
            ],
            'processors' => [
                PsrLogMessageProcessor::class,
                // Clase resuelta por el contenedor: debe ser invocable o
                // implementar Monolog\Processor\ProcessorInterface.
                // M-7: por defecto el processor de request-id (App\Http\Middleware\RequestId), que
                // inyecta `extra.request_id` leyendo el id de correlación del Context —el mismo que fija
                // el middleware al tomar/generar X-Request-Id y que se propaga a los jobs del outbox—.
                // Apuntar LOG_REQUEST_ID_PROCESSOR a UidProcessor::class para volver al id-por-proceso.
                env('LOG_REQUEST_ID_PROCESSOR', RequestId::class),
            ],
        ],

        'syslog' => [
            'driver' => 'syslog',
            'level' => env('LOG_LEVEL', 'debug'),
            'facility' => env('LOG_SYSLOG_FACILITY', LOG_USER),
            'replace_placeholders' => true,
        ],

        'errorlog' => [
            'driver' => 'errorlog',
            'level' => env('LOG_LEVEL', 'debug'),
            'replace_placeholders' => true,
        ],

        'null' => [
            'driver' => 'monolog',
            'handler' => NullHandler::class,
        ],

        'emergency' => [
            'path' => storage_path('logs/laravel.log'),
        ],

    ],

];
