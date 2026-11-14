<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Observabilidad (auditoría M-6 + Ola 5 del roadmap de alta disponibilidad)
|--------------------------------------------------------------------------
|
| Configuración del endpoint de métricas Prometheus, de las métricas HTTP, de los
| latidos, de los spans y del logging de consultas lentas. Vive en su propio archivo
| (no en logging.php/database.php, que tocan otros frentes) y es la ÚNICA puerta donde
| se leen estas envs: el resto del código lee de config() para no romper la regla
| larastan.noEnvCallsOutsideOfConfig (env() devuelve null cuando la config está cacheada).
|
| Todos los valores tienen un default funcional: un docker nuevo sin estas envs ya
| mide. En el entorno `testing` las métricas HTTP y los spans nacen APAGADOS para que
| la suite no escriba en Valkey; cada test que los prueba los enciende por config.
|
| Envs (ver .env.example y docs/observability.md):
|   METRICS_TOKEN                 token para scrapear /api/metrics (Bearer o X-Metrics-Token); vacío = derivado de APP_KEY
|   METRICS_IP_ALLOWLIST          IPs permitidas separadas por coma (alternativa/adición al token)
|   METRICS_REDIS_CONNECTION      conexión Redis donde se acumulan las métricas (default `default`)
|   METRICS_REDIS_PREFIX          prefijo de las claves de métricas (default `obs:`)
|   METRICS_CACHE_CONNECTION      conexión Redis de la que se lee el INFO de caché (default `cache`)
|   METRICS_HTTP_ENABLED          enciende las métricas HTTP (default true; false en testing)
|   METRICS_HTTP_SAMPLE_RATE      fracción de requests medidos, 0..1 (default 1.0)
|   METRICS_HEARTBEAT_ENABLED     enciende los latidos de scheduler y workers (default true)
|   METRICS_WORKER_HEARTBEAT_INTERVAL  segundos mínimos entre latidos de un worker (default 10)
|   SPANS_ENABLED                 enciende Span::measure (default true; false en testing)
|   SPANS_LOG_CHANNEL             canal de log de los spans (default: canal por defecto)
|   SPANS_LOG_LEVEL               nivel de log de los spans: debug|info|notice|warning (default debug)
|   SPANS_LOG_THRESHOLD_MS        solo se loguean los spans que duran al menos esto (default 100)
|   SLOW_QUERY_LOG_ENABLED        activa el listener de consultas lentas (default false)
|   SLOW_QUERY_THRESHOLD_MS       umbral en milisegundos (default 500)
|   SLOW_QUERY_LOG_CHANNEL        canal de log destino (default: canal por defecto; p. ej. `json`)
|
*/

// En la suite de tests la instrumentación que escribe en Valkey nace apagada (ver nota de arriba).
$instrumentByDefault = env('APP_ENV') !== 'testing';

return [

    // Endpoint /api/metrics: señales RED/USE para Prometheus. Fail-closed: sin token NI allowlist
    // el endpoint se niega (ver App\Http\Controllers\Api\V1\MetricsController).
    'metrics' => [
        // Sin METRICS_TOKEN se usa un token DERIVADO de APP_KEY (sha256 de "logistics-metrics:<APP_KEY>"):
        // el stack de observabilidad calcula el mismo valor, así Prometheus raspa sin configurar nada y el
        // endpoint sigue cerrado para quien no conozca la clave de la app. Sin APP_KEY no hay token.
        'token' => (static function (): ?string {
            $explicit = trim((string) env('METRICS_TOKEN', ''));
            if ($explicit !== '') {
                return $explicit;
            }

            $appKey = (string) env('APP_KEY', '');

            return $appKey !== '' ? hash('sha256', 'logistics-metrics:'.$appKey) : null;
        })(),
        'ip_allowlist' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('METRICS_IP_ALLOWLIST', '')),
        ))),

        // Dónde se acumulan contadores, histogramas y latidos (App\Metrics\MetricsStore). Por defecto la
        // conexión `default` (DB 0: colas/locks, durable y sin evicción) y NO la de caché: un
        // `cache:clear` hace FLUSHDB de la DB de caché y se llevaría los contadores y los latidos.
        'store' => [
            'connection' => env('METRICS_REDIS_CONNECTION', 'default'),
            'prefix' => env('METRICS_REDIS_PREFIX', 'obs:'),
        ],

        // Métricas RED del tráfico HTTP (App\Http\Middleware\RecordRequestMetrics). Una ida y vuelta a
        // Valkey por request, después de enviar la respuesta.
        'http' => [
            'enabled' => (bool) env('METRICS_HTTP_ENABLED', $instrumentByDefault),
            // Fracción de requests medidos (0..1). Cada muestra pesa 1/sample_rate para no sesgar tasas.
            'sample_rate' => (float) env('METRICS_HTTP_SAMPLE_RATE', 1.0),
            // Paths que no se miden (patrones de Request::is): el propio scrape y la sonda de vida.
            'except' => ['api/metrics', 'up'],
        ],

        // Latidos del scheduler y de los workers de cola (App\Observability\Heartbeat).
        'heartbeat' => [
            'enabled' => (bool) env('METRICS_HEARTBEAT_ENABLED', true),
            // Un worker escribe su latido como mucho una vez cada estos segundos.
            'worker_interval' => (int) env('METRICS_WORKER_HEARTBEAT_INTERVAL', 10),
        ],

        // Conexión Redis de la que se lee el INFO (hits/misses/memoria). Las estadísticas de INFO son de
        // toda la instancia de Valkey, sea cual sea la DB de la conexión.
        'cache_connection' => env('METRICS_CACHE_CONNECTION', 'cache'),

        // Conexión Redis de las colas (la misma que config/queue.php) y los nombres de cada cola,
        // para medir su profundidad por LLEN sin desincronizarse de lo que drenan los workers.
        'queue_connection' => env('REDIS_QUEUE_CONNECTION', 'default'),
        'queues' => [
            'default' => env('REDIS_QUEUE', 'default'),
            'outbox' => env('REDIS_OUTBOX_QUEUE', 'outbox'),
            'notifications' => env('REDIS_NOTIFICATIONS_QUEUE', 'notifications'),
            'planning' => env('REDIS_PLANNING_QUEUE', 'planning'),
            // Cola de los avisos fire-and-forget a SAP (misma env que config/logistics-sap-connector.php).
            'sap' => env('SAP_CONNECTOR_QUEUE', 'sap'),
            // Cola del drenado de pings GPS en modo buffered (misma env que config/logistics-telemetry.php).
            'telemetry' => env('TELEMETRY_INGEST_QUEUE', 'telemetry'),
        ],
    ],

    // Spans livianos (App\Observability\Span): cronometran caminos lentos, alimentan el histograma
    // logistics_span_duration_seconds y dejan una línea de log estructurado con el request_id.
    'spans' => [
        'enabled' => (bool) env('SPANS_ENABLED', $instrumentByDefault),
        'log_channel' => env('SPANS_LOG_CHANNEL'),
        'log_level' => env('SPANS_LOG_LEVEL', 'debug'),
        // Los spans más cortos que esto solo van al histograma (no al log), para no inundarlo.
        'log_threshold_ms' => (float) env('SPANS_LOG_THRESHOLD_MS', 100),
    ],

    // Logging de consultas lentas (DB::listen). Apagado por defecto para no ensuciar tests/desarrollo.
    'slow_query' => [
        'enabled' => (bool) env('SLOW_QUERY_LOG_ENABLED', false),
        'threshold_ms' => (float) env('SLOW_QUERY_THRESHOLD_MS', 500),
        'channel' => env('SLOW_QUERY_LOG_CHANNEL'),
    ],

];
