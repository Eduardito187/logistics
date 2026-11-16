<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Events\PickingUpdated;
use App\Support\Staff\StaffInbox;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Logistics\Shared\Presentation\Realtime\RealtimeChannels;
use Throwable;

/**
 * Emite `picking.updated` para la lista de preparación de una ciudad, fuera del request (cola `notifications`).
 *
 * Recibe la ciudad ya resuelta por el observador y las pistas del hecho (todo primitivo) y arma acá los canales
 * del tema `picking` que cubren esa ciudad ({@see RealtimeChannels::forEntity}). Sin ciudad, emite solo al canal
 * general (`picking.all`, oficina).
 *
 * Un solo intento (best-effort); sin transporte de tiempo real no hace nada. Nunca deja escapar un error: el
 * aviso es prescindible y la lista se refresca sola en el próximo ciclo de la app.
 */
final class BroadcastPickingUpdated implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public int $timeout = 15;

    public function __construct(
        public readonly ?string $city,
        public readonly ?string $shipmentId,
        public readonly ?string $orderReference,
        public readonly ?string $planId,
        public readonly ?string $vehicleId,
    ) {
        $this->onQueue((string) config('observability.metrics.queues.notifications', 'notifications'));
    }

    public function handle(): void
    {
        if (!StaffInbox::realtimeEnabled()) {
            return;
        }

        try {
            $channels = RealtimeChannels::forEntity(RealtimeChannels::TOPIC_PICKING, ['city' => $this->city]);

            PickingUpdated::dispatch($channels, $this->shipmentId, $this->orderReference, $this->planId, $this->vehicleId);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
