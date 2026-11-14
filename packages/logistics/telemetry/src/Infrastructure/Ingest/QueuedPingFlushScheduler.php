<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Infrastructure\Ingest;

use Illuminate\Support\Facades\Cache;
use Logistics\Telemetry\Application\Contract\PingFlushScheduler;
use Logistics\Telemetry\Infrastructure\Queue\FlushPingBufferJob;

/**
 * Programa el drenado de un fragmento como un job en la cola dedicada de telemetría, con retardo igual al
 * intervalo de flush (la ventana en la que se juntan lecturas).
 *
 * Coalescing: una marca `add()` (set-if-absent con TTL) por fragmento hace que, de todos los POST que
 * llegan dentro de la ventana, solo el primero encole el job. El job borra la marca al arrancar
 * (`started`), así lo que llegue durante el drenado programa el siguiente. La marca vence sola: si un job
 * se pierde, a lo sumo demora el próximo unos segundos (y el drenado de respaldo del scheduler cubre el
 * hueco).
 */
final readonly class QueuedPingFlushScheduler implements PingFlushScheduler
{
    private const MARKER_KEY = 'telemetry:ingest:flush-scheduled:';

    /** Vida mínima de la marca (segundos): cubre el retardo del job más la espera del worker. */
    private const MIN_MARKER_SECONDS = 10;

    public function schedule(int $shard, ?int $delaySeconds = null): void
    {
        $delay = max(0, $delaySeconds ?? (int) config('logistics-telemetry.ingest.buffer.flush_interval_seconds', 2));

        if (!Cache::add(self::MARKER_KEY.$shard, 1, max(self::MIN_MARKER_SECONDS, $delay * 5))) {
            return;
        }

        $pending = FlushPingBufferJob::dispatch($shard);
        if ($delay > 0) {
            $pending->delay($delay);
        }
    }

    public function started(int $shard): void
    {
        Cache::forget(self::MARKER_KEY.$shard);
    }
}
