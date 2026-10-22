<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Observabilidad (auditoría M-6)
|--------------------------------------------------------------------------
|
| Configuración del endpoint de métricas Prometheus y del logging de consultas
| lentas. Vive en su propio archivo (no en logging.php/database.php, que tocan
| otros frentes) y es la ÚNICA puerta donde se leen estas envs: el resto del
| código lee de config() para no romper la regla larastan.noEnvCallsOutsideOfConfig
| (env() devuelve null cuando la config está cacheada).
|
| Envs nuevas (ver .env.example / reportar al orquestador):
|   METRICS_TOKEN            token secreto para scrapear /api/metrics (Bearer o X-Metrics-Token)
|   METRICS_IP_ALLOWLIST     IPs permitidas separadas por coma (alternativa/adición al token)
|   SLOW_QUERY_LOG_ENABLED   activa el listener de consultas lentas (default false)
|   SLOW_QUERY_THRESHOLD_MS  umbral en milisegundos (default 500)
|   SLOW_QUERY_LOG_CHANNEL   canal de log destino (default: canal por defecto; p. ej. `json`)
|
*/

return [

    // Endpoint /api/metrics: señales RED/USE para Prometheus. Fail-closed: sin token NI allowlist
    // el endpoint se niega (ver App\Http\Controllers\Api\V1\MetricsController).
    'metrics' => [
        'token' => env('METRICS_TOKEN'),
        'ip_allowlist' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('METRICS_IP_ALLOWLIST', '')),
        ))),

        // Conexión Redis de las colas (la misma que config/queue.php) y los nombres de cada cola,
        // para medir su profundidad por LLEN sin desincronizarse de lo que drenan los workers.
        'queue_connection' => env('REDIS_QUEUE_CONNECTION', 'default'),
        'queues' => [
            'default' => env('REDIS_QUEUE', 'default'),
            'outbox' => env('REDIS_OUTBOX_QUEUE', 'outbox'),
            'notifications' => env('REDIS_NOTIFICATIONS_QUEUE', 'notifications'),
            'planning' => env('REDIS_PLANNING_QUEUE', 'planning'),
        ],
    ],

    // Logging de consultas lentas (DB::listen). Apagado por defecto para no ensuciar tests/desarrollo.
    'slow_query' => [
        'enabled' => (bool) env('SLOW_QUERY_LOG_ENABLED', false),
        'threshold_ms' => (float) env('SLOW_QUERY_THRESHOLD_MS', 500),
        'channel' => env('SLOW_QUERY_LOG_CHANNEL'),
    ],

];
