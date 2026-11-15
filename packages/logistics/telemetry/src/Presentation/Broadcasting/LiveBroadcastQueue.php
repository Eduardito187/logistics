<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Presentation\Broadcasting;

/**
 * Cola de los trabajos del fan-out en vivo de la app (`fleet.position`, `fleet.removed` y la emisión final
 * tras el throttle): la configurada en `logistics-telemetry.live.broadcast_queue` o, si no hay, la de los
 * avisos (la misma de `inbox.updated`), que siempre tiene worker.
 */
final class LiveBroadcastQueue
{
    public static function name(): string
    {
        $queue = config('logistics-telemetry.live.broadcast_queue');

        return is_string($queue) && $queue !== ''
            ? $queue
            : (string) config('observability.metrics.queues.notifications', 'notifications');
    }
}
