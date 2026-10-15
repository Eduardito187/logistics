<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Configuración de Sentry (monitoreo de errores y performance)
|--------------------------------------------------------------------------
|
| Este archivo deja Sentry PRE-CONFIGURADO sin instalar todavía el paquete.
| Mientras `sentry/sentry-laravel` no esté en el composer.json, este archivo
| es inerte: nadie lo lee y no cambia el comportamiento de la app. El día que
| se habilite observabilidad de errores basta con:
|
|   1. composer require sentry/sentry-laravel
|   2. Definir SENTRY_LARAVEL_DSN en el entorno (ver .env.example).
|   3. (opcional) php artisan sentry:publish --dsn=... para regenerar este
|      archivo con la versión exacta del paquete instalado; revisar el diff.
|
| El SDK de Sentry lee estas mismas claves, así que los valores y las envs ya
| quedan acordados con el equipo. Los nombres de las claves y de las envs
| siguen los de `sentry/sentry-laravel` para que el publish futuro no rompa
| nada. Ver docs/observability.md para la guía de enchufe y las métricas.
|
*/

return [

    // DSN del proyecto en Sentry. Vacío/no definido => el SDK queda desactivado
    // (no envía nada), que es justo lo que queremos hasta instalar el paquete.
    'dsn' => env('SENTRY_LARAVEL_DSN', env('SENTRY_DSN')),

    // Versión de release para agrupar errores por despliegue. Conviene inyectar
    // el SHA del commit o el tag en CI (SENTRY_RELEASE).
    'release' => env('SENTRY_RELEASE'),

    // Entorno lógico (production, staging, local). Cae al APP_ENV de la app.
    'environment' => env('SENTRY_ENVIRONMENT', env('APP_ENV', 'production')),

    // Datos personales en los eventos (IP, usuario, cookies). Default: false
    // para no filtrar PII sin decisión explícita.
    'send_default_pii' => env('SENTRY_SEND_DEFAULT_PII', false),

    // Muestreo de ERRORES: 1.0 = capturar el 100%. Bajar solo si el volumen de
    // errores llega a ser un problema de cuota.
    'sample_rate' => (float) env('SENTRY_SAMPLE_RATE', 1.0),

    // Muestreo de PERFORMANCE (transacciones/tracing). null => tracing apagado.
    // Empezar bajo (p. ej. 0.1) en producción para no inflar la cuota.
    'traces_sample_rate' => env('SENTRY_TRACES_SAMPLE_RATE') !== null
        ? (float) env('SENTRY_TRACES_SAMPLE_RATE')
        : null,

    // Muestreo de PROFILING, relativo a las transacciones ya muestreadas.
    // null => profiling apagado. Requiere la extensión Excimer.
    'profiles_sample_rate' => env('SENTRY_PROFILES_SAMPLE_RATE') !== null
        ? (float) env('SENTRY_PROFILES_SAMPLE_RATE')
        : null,

    // Adjuntar el stacktrace también a los mensajes (no solo a las excepciones).
    'attach_stacktrace' => env('SENTRY_ATTACH_STACKTRACE', true),

    // No reportar estas excepciones a Sentry (ruido esperado). Se completará con
    // las excepciones de dominio que no son incidentes (p. ej. validación,
    // 404, abort autenticación) cuando se instale el paquete.
    'ignore_exceptions' => [],

    // Transacciones a no trazar (healthchecks, assets) cuando el tracing esté on.
    'ignore_transactions' => [],

    /*
    | Integración con el breadcrumb/tracing de Laravel. El SDK solo lee esto si
    | el paquete está instalado; se deja preparado para cuando se encienda el
    | tracing de performance (ver métricas de latencia API en el doc).
    */
    'breadcrumbs' => [
        'logs' => env('SENTRY_BREADCRUMBS_LOGS_ENABLED', true),
        'cache' => env('SENTRY_BREADCRUMBS_CACHE_ENABLED', true),
        'sql_queries' => env('SENTRY_BREADCRUMBS_SQL_QUERIES_ENABLED', true),
        'sql_bindings' => env('SENTRY_BREADCRUMBS_SQL_BINDINGS_ENABLED', false),
        'queue_info' => env('SENTRY_BREADCRUMBS_QUEUE_INFO_ENABLED', true),
        'command_info' => env('SENTRY_BREADCRUMBS_COMMAND_JOBS_ENABLED', true),
        'http_client_requests' => env('SENTRY_BREADCRUMBS_HTTP_CLIENT_REQUESTS_ENABLED', true),
    ],

    'tracing' => [
        // Spans por cada pieza del request; útiles para ver dónde se va la
        // latencia de la API y el tiempo de las llamadas a OSRM.
        'queue_job_transactions' => env('SENTRY_TRACE_QUEUE_ENABLED', false),
        'queue_jobs' => env('SENTRY_TRACE_QUEUE_JOBS_ENABLED', true),
        'sql_queries' => env('SENTRY_TRACE_SQL_QUERIES_ENABLED', true),
        'redis_commands' => env('SENTRY_TRACE_REDIS_COMMANDS', false),
        'http_client_requests' => env('SENTRY_TRACE_HTTP_CLIENT_REQUESTS_ENABLED', true),
        // No reportar como error las respuestas HTTP de salida con estos códigos.
        'default_integrations' => env('SENTRY_TRACE_DEFAULT_INTEGRATIONS_ENABLED', true),
    ],

];
