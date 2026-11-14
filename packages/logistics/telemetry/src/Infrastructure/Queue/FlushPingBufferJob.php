<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Infrastructure\Queue;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Logistics\Telemetry\Application\Contract\PingFlushScheduler;
use Logistics\Telemetry\Infrastructure\Ingest\PingBufferDrainer;

/**
 * Drena un fragmento del buffer de ingesta de pings (modo `buffered`) en la cola dedicada `telemetry`.
 *
 * - Asíncrono: corre en el worker de la cola, nunca en el request del chofer.
 * - Idempotente: el drenado va bajo candado por fragmento y la ingesta deduplica por lectura; dos copias
 *   del job o un reintento no duplican nada.
 * - Autosostenido: si al terminar queda carga (presupuesto agotado, otro proceso tenía el candado o la
 *   pasada falló entera) se reprograma solo; con el fragmento vacío no deja nada encolado.
 *
 * Un solo intento de cola: lo no drenado sigue en el buffer y lo retoma el próximo job (o el drenado de
 * respaldo del scheduler); reintentar el mismo job no aporta nada.
 *
 * Lleva solo un primitivo (el número de fragmento).
 */
final class FlushPingBufferJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    /** Segundos máximos del job: holgado sobre `drain_seconds` y menor que el --timeout del worker. */
    public int $timeout = 60;

    public function __construct(
        private readonly int $shard,
    ) {
        $this->onQueue((string) config('logistics-telemetry.ingest.queue', 'telemetry'));
    }

    public function handle(PingBufferDrainer $drainer, PingFlushScheduler $scheduler): void
    {
        // Desde acá, un lote nuevo del fragmento vuelve a programar su propio drenado.
        $scheduler->started($this->shard);

        $result = $drainer->drain($this->shard);

        if ($result['remaining'] === 0) {
            return;
        }

        if ($result['stalled']) {
            $scheduler->schedule(
                $this->shard,
                max(1, (int) config('logistics-telemetry.ingest.buffer.failure_backoff_seconds', 15)),
            );

            return;
        }

        // Con el candado ajeno se espera el intervalo normal; con presupuesto agotado se sigue de inmediato.
        $scheduler->schedule($this->shard, $result['locked'] ? 0 : null);
    }

    public function shard(): int
    {
        return $this->shard;
    }
}
