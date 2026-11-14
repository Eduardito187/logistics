<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Infrastructure\Ingest;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Logistics\Telemetry\Application\Contract\PingIngestBuffer;
use Logistics\Telemetry\Application\Ingest\PingBufferFlusher;

/**
 * Drena un fragmento del buffer de ingesta bajo candado: repite pasadas del PingBufferFlusher hasta
 * vaciarlo o agotar el presupuesto de tiempo.
 *
 * El candado por fragmento (cache lock con vencimiento propio) garantiza UN drenado a la vez por
 * fragmento aunque haya varios workers y el drenado de respaldo del scheduler: las lecturas de un vehículo
 * nunca se procesan en paralelo. Si el proceso muere, el candado vence solo (no hay mutex colgado).
 *
 * Si una pasada falla ENTERA (base caída) se corta el bucle y se avisa con `stalled`, para que quien
 * llama espere antes de reintentar en vez de girar en vacío.
 *
 * @phpstan-type DrainResult array{locked: bool, flushes: int, batches: int, pings: int, failed_groups: int, buried: int, stalled: bool, remaining: int}
 */
final readonly class PingBufferDrainer
{
    private const LOCK_KEY = 'telemetry:ingest:flush-lock:';

    public function __construct(
        private PingBufferFlusher $flusher,
        private PingIngestBuffer $buffer,
    ) {}

    /**
     * @return DrainResult
     */
    public function drain(int $shard): array
    {
        $result = [
            'locked' => false,
            'flushes' => 0,
            'batches' => 0,
            'pings' => 0,
            'failed_groups' => 0,
            'buried' => 0,
            'stalled' => false,
            'remaining' => 0,
        ];

        $lock = Cache::lock(
            self::LOCK_KEY.$shard,
            max(5, (int) config('logistics-telemetry.ingest.buffer.lock_seconds', 60)),
        );

        if (!$lock->get()) {
            // Otro proceso ya está drenando este fragmento.
            $result['remaining'] = $this->buffer->depth($shard);

            return $result;
        }

        $result['locked'] = true;
        $deadline = microtime(true) + max(1, (int) config('logistics-telemetry.ingest.buffer.drain_seconds', 20));

        try {
            do {
                $report = $this->flusher->flush($shard);
                if ($report->isEmpty()) {
                    break;
                }

                $result['flushes']++;
                $result['batches'] += $report->batches;
                $result['pings'] += $report->pings;
                $result['failed_groups'] += $report->failedGroups;
                $result['buried'] += $report->buriedBatches;

                if ($report->errors !== []) {
                    Log::warning('telemetry.ingest.flush_failed', [
                        'shard' => $shard,
                        'failed_groups' => $report->failedGroups,
                        'retried_batches' => $report->retriedBatches,
                        'buried_batches' => $report->buriedBatches,
                        'errors' => array_slice($report->errors, 0, 5),
                    ]);
                }

                if ($report->stalled()) {
                    $result['stalled'] = true;

                    break;
                }
            } while (microtime(true) < $deadline);

            $result['remaining'] = $this->buffer->depth($shard);
        } finally {
            $lock->release();
        }

        return $result;
    }
}
