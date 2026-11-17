<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Evento de BROADCAST: cambió lo que ve el PREPARADOR (la lista de pedidos por preparar de su ciudad).
 *
 * Viaja por los canales del tema `picking` que cubren la ciudad del hecho (`picking.all` para la oficina y
 * `picking.city.<departamento>` para preparador/planificador de esa ciudad) con el nombre estable
 * `picking.updated`. Es solo el AVISO de «volvé a pedir la lista»: la carga lleva pistas (`shipment_id`,
 * `order_reference`, `plan_id`, `vehicle_id`) y `shipment_id` en null significa que cambió la lista en general.
 *
 * Se difunde por la COLA (`ShouldBroadcast`, nunca `ShouldBroadcastNow`): nada de I/O externo en el request.
 * Un solo intento: es best-effort; si el servidor de sockets no está, el aviso se pierde sin ruido. Con
 * `BROADCAST_CONNECTION=null|log` el trabajo que lo emite no hace nada.
 *
 * Carga primitiva (ids de negocio), nunca modelos de dominio.
 */
final class PickingUpdated implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;

    /** Nombre del evento en el cliente (desacoplado del FQCN). */
    public const NAME = 'picking.updated';

    /** Intentos del trabajo de difusión: uno, es best-effort. */
    public int $tries = 1;

    /** Tope en segundos del trabajo de difusión. */
    public int $timeout = 15;

    /**
     * @param list<string> $channels nombres de canal SIN el prefijo `private-` (los arma el shared)
     */
    public function __construct(
        public readonly array $channels,
        public readonly ?string $shipmentId,
        public readonly ?string $orderReference,
        public readonly ?string $planId,
        public readonly ?string $vehicleId,
    ) {}

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return array_map(static fn (string $channel): PrivateChannel => new PrivateChannel($channel), $this->channels);
    }

    public function broadcastAs(): string
    {
        return self::NAME;
    }

    /**
     * @return array{shipment_id: string | null, order_reference: string | null, plan_id: string | null, vehicle_id: string | null}
     */
    public function broadcastWith(): array
    {
        return [
            'shipment_id' => $this->shipmentId,
            'order_reference' => $this->orderReference,
            'plan_id' => $this->planId,
            'vehicle_id' => $this->vehicleId,
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
