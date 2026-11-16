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
 * el estado visible vigente, la referencia de la orden y las dimensiones de alcance del pedido —ciudad, nodo,
 * chofer y transportista— ({@see TrackingSignalReader}).
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
                self::channels($this->shipmentId, $snapshot),
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
     * Canales de la señal para que `tracking.updated` llegue a TODOS los roles por su dimensión de alcance:
     * el de la ficha del pedido (`order.{shipmentId}`) más los del tema tracking que cubren al pedido. El
     * helper del shared arma `tracking.all` (oficina) + chofer + transportista; a eso se suman, por cada
     * ciudad (oficina/planificador de esa ciudad) y cada nodo (tienda) de destino y origen, su canal del
     * tema. Todo deduplicado, preservando el orden; una dimensión ausente se omite.
     *
     * @param  array{cities: list<string>, nodes: list<string>, driver: string | null, carrier: string | null} $snapshot
     * @return list<string>
     */
    private static function channels(string $shipmentId, array $snapshot): array
    {
        $channels = ['order.'.$shipmentId];

        // Canal general del tema + chofer + transportista del pedido (dimensiones únicas, las arma forEntity).
        $topic = RealtimeChannels::forEntity(RealtimeChannels::TOPIC_TRACKING, [
            'driver' => $snapshot['driver'],
            'carrier' => $snapshot['carrier'],
        ]);

        // Ciudades (destino/origen) y nodos (destino/origen) del pedido: su canal del tema, uno por valor.
        foreach ($snapshot['cities'] as $city) {
            $topic[] = RealtimeChannels::city(RealtimeChannels::TOPIC_TRACKING, $city);
        }
        foreach ($snapshot['nodes'] as $node) {
            $topic[] = RealtimeChannels::node(RealtimeChannels::TOPIC_TRACKING, $node);
        }

        foreach ($topic as $channel) {
            if (is_string($channel) && $channel !== '' && !in_array($channel, $channels, true)) {
                $channels[] = $channel;
            }
        }

        return $channels;
    }
}
