<?php

declare(strict_types=1);

namespace Logistics\Tracking\Infrastructure\Realtime;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Logistics\Shared\Presentation\Realtime\RealtimeChannels;
use Logistics\Tracking\Presentation\Broadcasting\TrackingUpdated;
use Throwable;

/**
 * Emite `tracking.updated` para un pedido que cambió, fuera del request (cola `notifications`).
 *
 * Recibe solo el id del envío y el nombre del hecho de origen (todo primitivo) y resuelve ACÁ, al ejecutar,
 * el estado visible vigente, la referencia de la orden y las ciudades del pedido ({@see TrackingSignalReader}).
 * Así la señal apunta a lo que hay en la base cuando sale, aunque el trabajo haya esperado la ventana.
 *
 *  - Si el seguimiento de ese envío ya no existe, no emite.
 *  - Un solo intento: es best-effort; un error queda en el log y no se reintenta.
 *  - Sin transporte de tiempo real no hace nada (ni consulta ni emite).
 */
final class BroadcastTrackingUpdated implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public int $timeout = 15;

    public function __construct(
        public readonly string $shipmentId,
        public readonly string $event,
    ) {
        $this->onQueue((string) config('observability.metrics.queues.notifications', 'notifications'));
    }

    public function handle(TrackingSignalReader $reader): void
    {
        if (!TrackingRealtime::enabled()) {
            return;
        }

        try {
            $snapshot = $reader->resolve($this->shipmentId);
            if ($snapshot === null) {
                return;
            }

            TrackingUpdated::dispatch(
                self::channels($this->shipmentId, $snapshot['cities']),
                $this->shipmentId,
                $snapshot['order_reference'],
                $snapshot['status'],
                $this->event,
            );
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * Canales de la señal: el de la ficha del pedido (`order.{shipmentId}`) más los del tema tracking por
     * cada ciudad del pedido (`tracking.all` siempre, y `tracking.city.{departamento}` por cada código),
     * sin repetir.
     *
     * @param  list<string> $cities
     * @return list<string>
     */
    private static function channels(string $shipmentId, array $cities): array
    {
        $channels = ['order.'.$shipmentId, RealtimeChannels::all(RealtimeChannels::TOPIC_TRACKING)];

        foreach ($cities as $city) {
            $channel = RealtimeChannels::city(RealtimeChannels::TOPIC_TRACKING, $city);
            if ($channel !== null && !in_array($channel, $channels, true)) {
                $channels[] = $channel;
            }
        }

        return $channels;
    }
}
