<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Evento de BROADCAST: cambió lo que ve la TIENDA (lo que llegó o salió de su nodo).
 *
 * Viaja por los canales del tema `store` que cubren el nodo del hecho (`store.all` para la oficina y
 * `store.node.<uuid>` para el personal de esa tienda) con el nombre estable `store.updated`. Es solo el AVISO
 * de «volvé a mirar tu bandeja de tienda»: la carga lleva el `node_id` y, como pista, el `shipment_id` del
 * envío (null = cambió algo general del nodo).
 *
 * Se difunde por la COLA (`ShouldBroadcast`, nunca `ShouldBroadcastNow`). Un solo intento, best-effort. Con
 * `BROADCAST_CONNECTION=null|log` el trabajo que lo emite no hace nada. Carga primitiva.
 */
final class StoreUpdated implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;

    /** Nombre del evento en el cliente (desacoplado del FQCN). */
    public const NAME = 'store.updated';

    /** Intentos del trabajo de difusión: uno, es best-effort. */
    public int $tries = 1;

    /** Tope en segundos del trabajo de difusión. */
    public int $timeout = 15;

    /**
     * @param list<string> $channels nombres de canal SIN el prefijo `private-` (los arma el shared)
     */
    public function __construct(
        public readonly array $channels,
        public readonly string $nodeId,
        public readonly ?string $shipmentId,
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
     * @return array{node_id: string, shipment_id: string | null}
     */
    public function broadcastWith(): array
    {
        return [
            'node_id' => $this->nodeId,
            'shipment_id' => $this->shipmentId,
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
