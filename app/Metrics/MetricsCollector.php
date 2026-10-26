<?php

declare(strict_types=1);

namespace App\Metrics;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Recolecta las señales RED/USE clave de la plataforma y las renderiza en formato Prometheus.
 *
 * Objetivo (auditoría M-6): dar visibilidad a escala sin introducir carga pesada. Todas las lecturas
 * son baratas: `LLEN` sobre las listas de Valkey para la profundidad de colas y un único `GROUP BY status`
 * sobre el outbox para el backlog. Cada sonda tolera su dependencia ausente/caída y degrada a cero en vez
 * de romper el scrape (un `/metrics` que revienta deja ciego al monitoreo justo cuando más se necesita).
 *
 * Señales expuestas:
 *   - logistics_queue_depth{queue}            profundidad de las colas Redis (default/outbox/notifications/planning)
 *   - logistics_outbox_messages{status}       backlog del outbox por estado (pending/failed/dead_lettered)
 *   - logistics_outbox_backlog_total          atajo: pending + failed (lo pendiente de drenar)
 *   - logistics_failed_jobs_total             trabajos fallidos acumulados (tabla failed_jobs)
 *   - logistics_jobs_queued_total             trabajos en la cola `database` (si se usa ese driver)
 *   - logistics_up                            testigo de que el proceso responde (siempre 1)
 */
final class MetricsCollector
{
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

        $this->collectQueueDepth($formatter);
        $this->collectOutboxBacklog($formatter);
        $this->collectJobsHealth($formatter);

        return $formatter->render();
    }

    /**
     * Profundidad de cada cola Redis por `LLEN queues:<nombre>` (la clave que usa el driver redis de
     * Laravel). No cuenta delayed/reserved para mantener la lectura O(1); el backlog principal es la lista.
     */
    private function collectQueueDepth(PrometheusFormatter $formatter): void
    {
        $samples = [];

        foreach ($this->queues() as $label => $queue) {
            $samples[] = [
                'labels' => ['queue' => $label],
                'value' => $this->redisListLength('queues:'.$queue),
            ];
        }

        $formatter->metric(
            'logistics_queue_depth',
            'gauge',
            'Trabajos esperando en cada cola Redis (profundidad de la lista).',
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
     * Longitud de una lista Redis; cero si Redis no responde.
     */
    private function redisListLength(string $key): int
    {
        try {
            $connection = (string) config('observability.metrics.queue_connection', 'default');

            /** @var int|null $length */
            $length = Redis::connection($connection)->llen($key);

            return (int) $length;
        } catch (Throwable) {
            return 0;
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
