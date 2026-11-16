<?php

declare(strict_types=1);

namespace Logistics\Tracking\Presentation\Broadcasting;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Evento de BROADCAST: cambió el seguimiento de un PEDIDO (su estado visible, su avance por producto o la
 * ruta que lo transporta).
 *
 * Viaja por canales privados con el nombre estable `tracking.updated`:
 *  - `private-order.{shipment_id}`: la ficha de UN pedido (admin o app) escucha su propio envío.
 *  - el tema `tracking` por ciudad (`private-tracking.all` y `private-tracking.city.{departamento}`): el
 *    tablero de seguimiento de la oficina/planificación recibe el cambio de los pedidos de su alcance.
 *
 * Es solo la SEÑAL para que quien mira vuelva a pedir el estado: el detalle (paradas, ETA, productos) nunca
 * viaja por el socket, solo lo imprescindible para decidir si refrescar.
 *
 * Carga: `{shipment_id, order_reference, status, event}` — `status` es el estado visible VIGENTE al emitir
 * (no el del hecho que la disparó, que pudo quedar atrás tras la ventana anti-ráfaga); `event` es el nombre
 * del hecho de origen; `order_reference` puede ser null (filas sin referencia utilizable).
 *
 * Un mismo evento lleva varios canales: el transporte Pusher los publica en una sola llamada. Se difunde por
 * la COLA (`ShouldBroadcast`, nunca `ShouldBroadcastNow`) y con un solo intento: si el servidor de sockets no
 * está, la señal se pierde sin reintentos ni ruido (la vista conserva su refresco por tiempo como respaldo).
 */
final class TrackingUpdated implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;

    /** Nombre del evento en el cliente (desacoplado del FQCN). */
    public const NAME = 'tracking.updated';

    /** Tope de canales por evento (límite de canales por publicación del protocolo Pusher). */
    public const MAX_CHANNELS = 100;

    /** Intentos del trabajo de difusión: uno, es best-effort. */
    public int $tries = 1;

    /** Tope en segundos del trabajo de difusión. */
    public int $timeout = 15;

    /**
     * @param list<string> $channelNames Nombres de canal SIN el prefijo `private-` (los de routes/channels.php).
     */
    public function __construct(
        public readonly array $channelNames,
        public readonly string $shipmentId,
        public readonly ?string $orderReference,
        public readonly string $status,
        public readonly string $event,
    ) {}

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        $channels = [];
        foreach (array_slice($this->channelNames, 0, self::MAX_CHANNELS) as $name) {
            if ($name !== '') {
                $channels[] = new PrivateChannel($name);
            }
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return self::NAME;
    }

    /**
     * @return array{shipment_id: string, order_reference: string|null, status: string, event: string}
     */
    public function broadcastWith(): array
    {
        return [
            'shipment_id' => $this->shipmentId,
            'order_reference' => $this->orderReference,
            'status' => $this->status,
            'event' => $this->event,
        ];
    }

    /**
     * Cola del trabajo de difusión: la misma de los avisos (su nombre real sale de la config).
     */
    public function broadcastQueue(): string
    {
        return (string) config('observability.metrics.queues.notifications', 'notifications');
    }
}
