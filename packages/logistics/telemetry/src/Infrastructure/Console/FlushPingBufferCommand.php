<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Infrastructure\Console;

use Illuminate\Console\Command;
use Logistics\Telemetry\Application\Contract\PingIngestBuffer;
use Logistics\Telemetry\Infrastructure\Ingest\PingBufferDrainer;
use Throwable;

/**
 * Drenado de RESPALDO del buffer de ingesta de pings y herramienta de operación.
 *
 * El camino normal es el job de la cola `telemetry`; este comando corre cada minuto en el scheduler para
 * que el buffer NUNCA quede varado (worker caído, job perdido, cambio de `buffered` a `sync` con lotes
 * pendientes). Usa el mismo candado por fragmento que el job, así que no se pisan. Con el buffer vacío
 * cuesta un par de LLEN por fragmento.
 *
 *   --status           solo muestra la profundidad por fragmento y los descartes (no drena).
 *   --requeue-dead=N   reencola hasta N lotes de la lista de descartes antes de drenar.
 *
 * Nunca falla el scheduler: si Valkey no responde informa y sale en éxito (no hay nada que drenar).
 * Texto visible en inglés (regla de admin/consola); comentarios en español.
 */
final class FlushPingBufferCommand extends Command
{
    protected $signature = 'telemetry:flush-ping-buffer
        {--status : Only print the buffer depth per shard and the dead-letter count}
        {--requeue-dead=0 : Requeue up to N dead-lettered batches before draining}';

    protected $description = 'Drain the buffered GPS ping ingest (safety net for the telemetry queue worker).';

    public function handle(PingIngestBuffer $buffer, PingBufferDrainer $drainer): int
    {
        try {
            $requeue = (int) $this->option('requeue-dead');
            if ($requeue > 0) {
                $this->info(sprintf('Requeued %d dead-lettered batch(es).', $buffer->resurrect($requeue)));
            }

            if ((bool) $this->option('status')) {
                return $this->status($buffer);
            }

            $pings = 0;
            $remaining = 0;
            for ($shard = 0; $shard < $buffer->shards(); $shard++) {
                if ($buffer->depth($shard) === 0) {
                    continue;
                }

                $result = $drainer->drain($shard);
                $pings += $result['pings'];
                $remaining += $result['remaining'];
            }

            $this->info(sprintf('Ping buffer drained: %d ping(s) ingested, %d batch(es) still buffered.', $pings, $remaining));
        } catch (Throwable $e) {
            $this->warn('Ping buffer unavailable: '.$e->getMessage());
        }

        return self::SUCCESS;
    }

    private function status(PingIngestBuffer $buffer): int
    {
        $total = 0;
        for ($shard = 0; $shard < $buffer->shards(); $shard++) {
            $depth = $buffer->depth($shard);
            $total += $depth;
            $this->line(sprintf('shard %d: %d batch(es)', $shard, $depth));
        }

        $this->line(sprintf('total: %d batch(es), dead-lettered: %d', $total, $buffer->deadCount()));

        return self::SUCCESS;
    }
}
