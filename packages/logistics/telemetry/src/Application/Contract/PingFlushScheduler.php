<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Application\Contract;

/**
 * Puerto que programa el drenado asíncrono de un fragmento del buffer de ingesta. La implementación de
 * Infrastructure lo traduce a un job en la cola dedicada; la Application solo expresa la intención.
 *
 * `schedule` es barato e idempotente dentro de la ventana de flush: muchos POST seguidos del mismo
 * fragmento programan UN solo drenado.
 */
interface PingFlushScheduler
{
    /**
     * Programa el drenado del fragmento. Sin `$delaySeconds` usa el intervalo de flush configurado.
     */
    public function schedule(int $shard, ?int $delaySeconds = null): void;

    /**
     * Avisa que el drenado programado ya arrancó: a partir de acá un lote nuevo vuelve a programar otro.
     */
    public function started(int $shard): void;
}
