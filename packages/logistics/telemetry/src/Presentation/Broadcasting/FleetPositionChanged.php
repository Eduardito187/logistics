<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Presentation\Broadcasting;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Logistics\Shared\Presentation\Realtime\RealtimeChannels;

/**
 * Evento de BROADCAST para la app: cambió la posición en vivo de un camión.
 *
 * Viaja con el nombre estable `fleet.position` y lleva EL MISMO objeto que un elemento de
 * `GET /api/v1/app/telemetry/fleet/live` (`VehicleLiveView::toArray()`): la app lo aplica sobre su lista por
 * `vehicle_id`, sin volver a pedir la flota.
 *
 * Canales (privados; los nombres salen del contrato compartido `RealtimeChannels`, tema `fleet`):
 *  - `private-fleet.all`             : quien ve todas las ciudades.
 *  - `private-fleet.city.<ciudad>`   : el personal de la ciudad del camión (departamento de su nodo base).
 *                                      Solo se suma si la ciudad se pudo resolver.
 *
 * Se difunde por la COLA (`ShouldBroadcast`, nunca `ShouldBroadcastNow`): nada de I/O externo en la ingesta.
 * Un solo intento a propósito: si el servidor de sockets no está, la posición se pierde sin reintentos (la
 * siguiente la pisa y `fleet/live` sigue siendo la fuente de verdad). Con `BROADCAST_CONNECTION=null|log`
 * no emite.
 *
 * Carga primitiva (array), nunca objetos de dominio ni modelos.
 */
final class FleetPositionChanged implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;

    /** Nombre del evento en el cliente (desacoplado del FQCN). */
    public const string NAME = 'fleet.position';

    /** Intentos del trabajo de difusión: uno, es best-effort. */
    public int $tries = 1;

    /** Tope en segundos del trabajo de difusión. */
    public int $timeout = 15;

    /**
     * @param array{vehicle_id: string, lat: float, lng: float, speed_kph: float|null, heading: int|null, ignition: bool|null, moving: bool, route_id: string|null, recorded_at: string, plate: string|null, driver_id: string|null, driver_name: string|null, updated_at: string|null, base_node_id: string|null, base_node_name: string|null, city: string|null} $position forma primitiva de VehicleLiveView
     */
    public function __construct(
        public readonly array $position,
    ) {}

    /**
     * Canales del tema `fleet` según la ciudad del camión (la del propio objeto, `city`).
     *
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return array_map(
            static fn (string $name): PrivateChannel => new PrivateChannel($name),
            RealtimeChannels::forEvent(RealtimeChannels::TOPIC_FLEET, $this->position['city']),
        );
    }

    public function broadcastAs(): string
    {
        return self::NAME;
    }

    /**
     * El objeto de `fleet/live`, tal cual.
     *
     * @return array{vehicle_id: string, lat: float, lng: float, speed_kph: float|null, heading: int|null, ignition: bool|null, moving: bool, route_id: string|null, recorded_at: string, plate: string|null, driver_id: string|null, driver_name: string|null, updated_at: string|null, base_node_id: string|null, base_node_name: string|null, city: string|null}
     */
    public function broadcastWith(): array
    {
        return $this->position;
    }

    /**
     * Cola del trabajo de difusión: la configurada para el fan-out en vivo o, si no hay, la de los avisos.
     */
    public function broadcastQueue(): string
    {
        return LiveBroadcastQueue::name();
    }
}
