<?php

declare(strict_types=1);

namespace App\Metrics;

use App\Observability\Heartbeat;
use Illuminate\Database\Connection;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Logistics\Telemetry\Application\Contract\PingIngestBuffer;
use Throwable;

/**
 * Recolecta las señales RED/USE clave de la plataforma y las renderiza en formato Prometheus.
 *
 * Objetivo (auditoría M-6 + Ola 5 del roadmap de alta disponibilidad): dar visibilidad a escala sin
 * introducir carga pesada. Cada sonda es barata (O(1) o respaldada por índice), tolera su dependencia
 * ausente/caída y degrada a cero en vez de romper el scrape (un `/metrics` que revienta deja ciego al
 * monitoreo justo cuando más se necesita).
 *
 * Convención de las métricas de "edad" (`*_age_seconds` de latidos): `-1` significa "nunca se observó"
 * (o no se pudo leer). Se usa `-1` y no `0` porque `0` se leería como "recién latió" y taparía justo la
 * falla que el latido debe delatar.
 *
 * Señales expuestas (detalle, tipos y etiquetas en docs/observability.md):
 *   - logistics_up
 *   - logistics_http_requests_total{route,method,status,gate}
 *   - logistics_http_request_duration_seconds{route,method,gate}    (histograma)
 *   - logistics_span_duration_seconds{span}                         (histograma)
 *   - logistics_queue_depth{queue} / logistics_queue_delayed{queue} / logistics_queue_reserved{queue}
 *   - logistics_queue_worker_last_activity_age_seconds{queue}
 *   - logistics_scheduler_last_tick_age_seconds
 *   - logistics_outbox_messages{status} / logistics_outbox_backlog_total
 *   - logistics_outbox_oldest_pending_age_seconds
 *   - logistics_failed_jobs_total / logistics_jobs_queued_total
 *   - logistics_telemetry_ingest_buffer_depth{shard} / logistics_telemetry_ingest_dead_batches
 *   - logistics_db_connections{state} / logistics_db_max_connections
 *   - logistics_db_replication_lag_bytes{client}
 *   - logistics_cache_hits_total / logistics_cache_misses_total / logistics_cache_memory_bytes
 */
final class MetricsCollector
{
    /** Valor de una métrica de edad cuando el latido nunca se observó. */
    private const NEVER = -1;

    /** Un latido de worker de una cola no configurada deja de exponerse pasado este tiempo (segundos). */
    private const UNKNOWN_WORKER_RETENTION = 86400;

    /** Estados de conexión que se exponen siempre (aunque valgan 0) para que la serie sea estable. */
    private const CONNECTION_STATES = ['active', 'idle', 'idle in transaction'];

    public function __construct(private readonly MetricsStore $store) {}

    /**
     * Nombres de las colas Redis a sondear. Se leen de config/observability.php (que a su vez refleja
     * las envs de config/queue.php) para coincidir con lo que realmente drenan los workers del supervisor.
     *
     * @return array<string, string> etiqueta lógica => nombre real de la cola
     */
    private function queues(): array
    {
        /** @var array<string, string> */
        return config('observability.metrics.queues', []);
    }

    /**
     * Arma el cuerpo de texto Prometheus con todas las señales.
     */
    public function render(): string
    {
        $formatter = new PrometheusFormatter;

        $formatter->scalar('logistics_up', 'gauge', 'Siempre 1: el proceso de la app responde el scrape.', 1);

        $this->collectHttp($formatter);
        $this->collectSpans($formatter);
        $this->collectQueues($formatter);
        $this->collectHeartbeats($formatter);
        $this->collectOutboxBacklog($formatter);
        $this->collectJobsHealth($formatter);
        $this->collectTelemetryIngest($formatter);
        $this->collectDatabase($formatter);
        $this->collectCache($formatter);

        return $formatter->render();
    }

    /**
     * Métricas RED del tráfico HTTP, acumuladas en Valkey por el middleware RecordRequestMetrics.
     */
    private function collectHttp(PrometheusFormatter $formatter): void
    {
        $requests = [];
        foreach ($this->store->counters(MetricsStore::HTTP_REQUESTS) as $series => $value) {
            $labels = MetricsStore::labels((string) $series);
            if (count($labels) !== 4) {
                continue;
            }

            $requests[] = [
                'labels' => ['route' => $labels[0], 'method' => $labels[1], 'status' => $labels[2], 'gate' => $labels[3]],
                'value' => $value,
            ];
        }

        $formatter->metric(
            'logistics_http_requests_total',
            'counter',
            'Requests HTTP atendidos, por patrón de ruta, método, código de estado y puerta.',
            $requests,
        );

        $durations = [];
        foreach ($this->store->histogram(MetricsStore::HTTP_DURATION) as $series => $data) {
            $labels = MetricsStore::labels((string) $series);
            if (count($labels) !== 3) {
                continue;
            }

            $durations[] = [
                'labels' => ['route' => $labels[0], 'method' => $labels[1], 'gate' => $labels[2]],
                'buckets' => $data['buckets'],
                'sum' => $data['sum'],
            ];
        }

        $formatter->histogram(
            'logistics_http_request_duration_seconds',
            'Duración de los requests HTTP en segundos, por patrón de ruta, método y puerta.',
            MetricsStore::BUCKETS,
            $durations,
        );
    }

    /**
     * Duración de los caminos lentos instrumentados con App\Observability\Span.
     */
    private function collectSpans(PrometheusFormatter $formatter): void
    {
        $spans = [];
        foreach ($this->store->histogram(MetricsStore::SPAN_DURATION) as $series => $data) {
            $spans[] = [
                'labels' => ['span' => (string) $series],
                'buckets' => $data['buckets'],
                'sum' => $data['sum'],
            ];
        }

        $formatter->histogram(
            'logistics_span_duration_seconds',
            'Duración en segundos de los bloques instrumentados con Span (caminos lentos).',
            MetricsStore::BUCKETS,
            $spans,
        );
    }

    /**
     * Estado de cada cola Redis en UNA ida y vuelta (pipeline), siempre O(1) por cola:
     *   - `LLEN queues:<nombre>`            trabajos listos esperando un worker
     *   - `ZCARD queues:<nombre>:delayed`   trabajos diferidos (delay / backoff de reintento)
     *   - `ZCARD queues:<nombre>:reserved`  trabajos tomados por un worker y aún sin confirmar
     * Son las claves que usa el driver redis de Laravel. Si Valkey no responde, las tres quedan en cero.
     */
    private function collectQueues(PrometheusFormatter $formatter): void
    {
        $queues = $this->queues();
        $names = array_values($queues);
        $results = [];

        try {
            $connection = (string) config('observability.metrics.queue_connection', 'default');

            $redis = Redis::connection($connection);
            assert($redis instanceof PhpRedisConnection);

            $raw = $redis->pipeline(static function (mixed $pipe) use ($names): void {
                foreach ($names as $name) {
                    $pipe->llen('queues:'.$name);
                    $pipe->zcard('queues:'.$name.':delayed');
                    $pipe->zcard('queues:'.$name.':reserved');
                }
            });

            $results = is_array($raw) ? array_values($raw) : [];
        } catch (Throwable) {
            // Valkey caído: se exponen ceros.
        }

        $depth = [];
        $delayed = [];
        $reserved = [];
        $index = 0;

        foreach ($queues as $label => $_) {
            $labels = ['queue' => $label];
            $depth[] = ['labels' => $labels, 'value' => (int) ($results[$index] ?? 0)];
            $delayed[] = ['labels' => $labels, 'value' => (int) ($results[$index + 1] ?? 0)];
            $reserved[] = ['labels' => $labels, 'value' => (int) ($results[$index + 2] ?? 0)];
            $index += 3;
        }

        $formatter->metric(
            'logistics_queue_depth',
            'gauge',
            'Trabajos esperando en cada cola Redis (profundidad de la lista).',
            $depth,
        );

        $formatter->metric(
            'logistics_queue_delayed',
            'gauge',
            'Trabajos diferidos en cada cola Redis (delay o backoff de reintento).',
            $delayed,
        );

        $formatter->metric(
            'logistics_queue_reserved',
            'gauge',
            'Trabajos tomados por un worker y aún sin confirmar, en cada cola Redis.',
            $reserved,
        );
    }

    /**
     * Latidos del scheduler y de los workers de cola (los escribe App\Observability\Heartbeat). Edad en
     * segundos desde el último latido; `-1` si nunca latió (ver la convención en el docblock de la clase).
     */
    private function collectHeartbeats(PrometheusFormatter $formatter): void
    {
        $beats = $this->store->heartbeats();
        $now = time();
        $age = static fn (?int $timestamp): int => $timestamp === null ? self::NEVER : max(0, $now - $timestamp);

        $formatter->scalar(
            'logistics_scheduler_last_tick_age_seconds',
            'gauge',
            'Segundos desde el último latido del scheduler (-1 = nunca latió). Sano: menos de 120.',
            $age($beats[Heartbeat::SCHEDULER] ?? null),
        );

        $samples = [];
        $configured = [];

        foreach ($this->queues() as $label => $queue) {
            $configured[Heartbeat::WORKER_PREFIX.$queue] = true;
            $samples[] = [
                'labels' => ['queue' => $label],
                'value' => $age($beats[Heartbeat::WORKER_PREFIX.$queue] ?? null),
            ];
        }

        // Workers de colas que no están en la config (p. ej. una cola nueva): se exponen con su nombre
        // real mientras sigan latiendo, y se dejan caer cuando el latido es viejo para no arrastrar una
        // serie muerta para siempre.
        foreach ($beats as $name => $timestamp) {
            $name = (string) $name;

            if (!str_starts_with($name, Heartbeat::WORKER_PREFIX) || isset($configured[$name])) {
                continue;
            }

            if ($now - $timestamp > self::UNKNOWN_WORKER_RETENTION) {
                continue;
            }

            $samples[] = [
                'labels' => ['queue' => substr($name, strlen(Heartbeat::WORKER_PREFIX))],
                'value' => $age($timestamp),
            ];
        }

        $formatter->metric(
            'logistics_queue_worker_last_activity_age_seconds',
            'gauge',
            'Segundos desde la última vuelta del bucle de un worker de esa cola (-1 = nunca se vio).',
            $samples,
        );
    }

    /**
     * Backlog del outbox por estado. Un único `GROUP BY status` (hay índice sobre status) evita varios
     * scans. Se exponen siempre los tres estados relevantes aunque falte alguno (valor 0), para que la
     * serie de tiempo sea estable en Prometheus.
     */
    private function collectOutboxBacklog(PrometheusFormatter $formatter): void
    {
        $counts = ['pending' => 0, 'failed' => 0, 'dead_lettered' => 0];

        try {
            $rows = DB::table('outbox_messages')
                ->selectRaw('status, count(*) as c')
                ->whereIn('status', array_keys($counts))
                ->groupBy('status')
                ->pluck('c', 'status');

            foreach ($counts as $status => $_) {
                $counts[$status] = (int) ($rows[$status] ?? 0);
            }
        } catch (Throwable) {
            // Tabla ausente en este entorno: se exponen ceros.
        }

        $samples = [];
        foreach ($counts as $status => $value) {
            $samples[] = ['labels' => ['status' => $status], 'value' => $value];
        }

        $formatter->metric(
            'logistics_outbox_messages',
            'gauge',
            'Mensajes del outbox por estado (lag/backlog hacia sistemas externos).',
            $samples,
        );

        $formatter->scalar(
            'logistics_outbox_backlog_total',
            'gauge',
            'Mensajes del outbox pendientes de drenar (pending + failed).',
            $counts['pending'] + $counts['failed'],
        );

        $formatter->scalar(
            'logistics_outbox_oldest_pending_age_seconds',
            'gauge',
            'Edad en segundos del mensaje pendiente más viejo del outbox (0 si no hay pendientes).',
            $this->oldestPendingAge(),
        );
    }

    /**
     * Edad del pendiente más viejo del outbox: latencia real de integración.
     *
     * Se toma el pendiente de `available_at` más antiguo (`ORDER BY available_at LIMIT 1`) y no un
     * `min(created_at)`: la consulta la resuelve el índice existente `(status, available_at)` en una
     * sola bajada, mientras que `min(created_at)` obligaría a recorrer TODOS los pendientes, justo
     * cuando el backlog es grande. Para un pendiente (nunca intentado) `available_at` nace igual a
     * `created_at`, así que es el mismo mensaje salvo publicaciones diferidas.
     *
     * En PostgreSQL la resta la hace el motor (`now() - created_at`), para no depender de la zona
     * horaria con la que PHP interprete la columna; en otro motor se cae a `strtotime`.
     */
    private function oldestPendingAge(): int
    {
        try {
            $query = DB::table('outbox_messages')
                ->where('status', 'pending')
                ->orderBy('available_at')
                ->limit(1);

            if ($this->postgres() !== null) {
                // `value()` respeta el selectRaw ya fijado y devuelve la única columna de la fila (o null).
                $age = $query->selectRaw('extract(epoch from (now() - created_at)) as age')->value('age');

                return is_numeric($age) ? max(0, (int) $age) : 0;
            }

            $createdAt = $query->value('created_at');
            $timestamp = is_string($createdAt) ? strtotime($createdAt) : false;

            return $timestamp === false ? 0 : max(0, time() - $timestamp);
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * Salud de la cola `database`: trabajos fallidos acumulados y trabajos encolados. Tolera que las
     * tablas no existan (si las colas corren sobre Redis puede que `jobs` no exista).
     */
    private function collectJobsHealth(PrometheusFormatter $formatter): void
    {
        $formatter->scalar(
            'logistics_failed_jobs_total',
            'gauge',
            'Trabajos fallidos acumulados (tabla failed_jobs).',
            $this->tableCount('failed_jobs'),
        );

        $formatter->scalar(
            'logistics_jobs_queued_total',
            'gauge',
            'Trabajos en la cola database a la espera de un worker.',
            $this->tableCount('jobs'),
        );
    }

    /**
     * Buffer de ingesta de pings GPS (Ola 6, modo `buffered`): lotes en espera por fragmento y lotes
     * apartados tras agotar reintentos. En modo `sync` el buffer está vacío y ambas series valen cero. Si
     * Valkey no responde se exponen ceros (LLEN por fragmento: O(1)).
     */
    private function collectTelemetryIngest(PrometheusFormatter $formatter): void
    {
        $depth = [];
        $dead = 0;

        try {
            $buffer = app(PingIngestBuffer::class);
            for ($shard = 0; $shard < $buffer->shards(); $shard++) {
                $depth[] = ['labels' => ['shard' => (string) $shard], 'value' => $buffer->depth($shard)];
            }
            $dead = $buffer->deadCount();
        } catch (Throwable) {
            $depth = [];
        }

        $formatter->metric(
            'logistics_telemetry_ingest_buffer_depth',
            'gauge',
            'Lotes de pings GPS en el buffer de ingesta (en espera más reservados), por fragmento.',
            $depth,
        );

        $formatter->scalar(
            'logistics_telemetry_ingest_dead_batches',
            'gauge',
            'Lotes de pings GPS apartados tras agotar sus reintentos (recuperables con --requeue-dead).',
            $dead,
        );
    }

    /**
     * Saturación de PostgreSQL: conexiones por estado contra el máximo, y lag de replicación. Las vistas
     * `pg_*` SOLO se consultan si el driver es pgsql; en otro motor se exponen las series vacías/cero.
     * Se fuerza el nodo de ESCRITURA (`useReadPdo = false`): con réplicas de lectura configuradas, estas
     * vistas solo dicen la verdad en el primario.
     */
    private function collectDatabase(PrometheusFormatter $formatter): void
    {
        $connection = $this->postgres();

        $states = array_fill_keys(self::CONNECTION_STATES, 0);
        $max = 0;
        $lag = [];

        if ($connection !== null) {
            try {
                $rows = $connection->select(
                    "SELECT coalesce(state, 'unknown') AS state, count(*) AS c FROM pg_stat_activity "
                    .'WHERE datname = current_database() GROUP BY 1',
                    [],
                    false,
                );

                foreach ($rows as $row) {
                    $states[(string) $row->state] = (int) $row->c;
                }
            } catch (Throwable) {
                // Sin permiso o vista no disponible: quedan los ceros.
            }

            try {
                $row = $connection->selectOne("SELECT current_setting('max_connections')::int AS max", [], false);
                $max = (int) ($row->max ?? 0);
            } catch (Throwable) {
                // Se expone 0.
            }

            try {
                // Sin standbys conectados la vista está vacía y la métrica sale sin muestras. En un standby
                // `pg_current_wal_lsn()` lanza: se atrapa y la serie queda vacía.
                $rows = $connection->select(
                    "SELECT coalesce(host(client_addr), nullif(application_name, ''), 'unknown') AS client, "
                    .'coalesce(pg_wal_lsn_diff(pg_current_wal_lsn(), replay_lsn), 0)::bigint AS lag_bytes '
                    .'FROM pg_stat_replication',
                    [],
                    false,
                );

                foreach ($rows as $row) {
                    $lag[] = ['labels' => ['client' => (string) $row->client], 'value' => (int) $row->lag_bytes];
                }
            } catch (Throwable) {
                // Sin replicación o sin permiso: serie vacía.
            }
        }

        $samples = [];
        foreach ($states as $state => $value) {
            $samples[] = ['labels' => ['state' => (string) $state], 'value' => $value];
        }

        $formatter->metric(
            'logistics_db_connections',
            'gauge',
            'Conexiones a la base de la plataforma en PostgreSQL, por estado (pg_stat_activity).',
            $samples,
        );

        $formatter->scalar(
            'logistics_db_max_connections',
            'gauge',
            'Límite max_connections de PostgreSQL (0 si no se pudo leer).',
            $max,
        );

        $formatter->metric(
            'logistics_db_replication_lag_bytes',
            'gauge',
            'Bytes de WAL que le falta aplicar a cada standby (pg_stat_replication). Sin muestras si no hay standby.',
            $lag,
        );
    }

    /**
     * Efectividad y memoria de Valkey leídas de un único `INFO` (estadísticas de TODA la instancia, no
     * solo de la base de caché). Cero si Valkey no responde.
     */
    private function collectCache(PrometheusFormatter $formatter): void
    {
        $info = [];

        try {
            $connection = (string) config('observability.metrics.cache_connection', 'cache');
            $raw = Redis::connection($connection)->info();

            foreach (is_array($raw) ? $raw : [] as $key => $value) {
                // phpredis devuelve el INFO plano; predis lo agrupa por sección: se aplana un nivel.
                if (is_array($value)) {
                    foreach ($value as $inner => $innerValue) {
                        $info[(string) $inner] = $innerValue;
                    }

                    continue;
                }

                $info[(string) $key] = $value;
            }
        } catch (Throwable) {
            // Valkey caído: se exponen ceros.
        }

        $read = static function (string $key) use ($info): int {
            $value = $info[$key] ?? 0;

            return is_numeric($value) ? (int) $value : 0;
        };

        $formatter->scalar(
            'logistics_cache_hits_total',
            'counter',
            'Lecturas de Valkey que encontraron la clave (keyspace_hits de la instancia).',
            $read('keyspace_hits'),
        );

        $formatter->scalar(
            'logistics_cache_misses_total',
            'counter',
            'Lecturas de Valkey que no encontraron la clave (keyspace_misses de la instancia).',
            $read('keyspace_misses'),
        );

        $formatter->scalar(
            'logistics_cache_memory_bytes',
            'gauge',
            'Memoria usada por Valkey en bytes (used_memory).',
            $read('used_memory'),
        );
    }

    /**
     * Conexión por defecto si es PostgreSQL; null en otro motor o si no se puede resolver.
     */
    private function postgres(): ?Connection
    {
        try {
            $connection = DB::connection();

            return $connection->getDriverName() === 'pgsql' ? $connection : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Conteo total de una tabla; cero si la tabla no existe o la consulta falla.
     */
    private function tableCount(string $table): int
    {
        try {
            return DB::table($table)->count();
        } catch (Throwable) {
            return 0;
        }
    }
}
