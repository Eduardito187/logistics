<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Events\StoreUpdated;
use App\Support\Staff\StaffInbox;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Logistics\Shared\Presentation\Realtime\RealtimeChannels;
use Throwable;

/**
 * Emite `store.updated` para un nodo (tienda) que cambió, fuera del request (cola `notifications`).
 *
 * Recibe el nodo ya resuelto por el observador y, como pista, el envío, y arma acá los canales del tema `store`
 * que cubren ese nodo ({@see RealtimeChannels::forEntity}): `store.all` (oficina) + `store.node.<uuid>` (la
 * tienda). Un solo intento (best-effort); sin transporte de tiempo real no hace nada. Nunca deja escapar un error.
 */
final class BroadcastStoreUpdated implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public int $timeout = 15;

    public function __construct(
        public readonly string $nodeId,
        public readonly ?string $shipmentId,
    ) {
        $this->onQueue((string) config('observability.metrics.queues.notifications', 'notifications'));
    }

    public function handle(): void
    {
        if (!StaffInbox::realtimeEnabled()) {
            return;
        }

        try {
            $channels = RealtimeChannels::forEntity(RealtimeChannels::TOPIC_STORE, ['node' => $this->nodeId]);

            StoreUpdated::dispatch($channels, $this->nodeId, $this->shipmentId);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
