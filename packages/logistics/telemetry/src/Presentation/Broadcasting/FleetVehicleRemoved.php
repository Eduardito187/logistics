<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Presentation\Broadcasting;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Logistics\Shared\Presentation\Realtime\RealtimeChannels;

/**
 * Evento de BROADCAST para la app: un camión dejó de ser parte de la flota en vivo porque se dio de baja en
 * flota. Viaja con el nombre estable `fleet.removed` y la app lo aplica quitando ese `vehicle_id` de su
 * lista (el mismo camión deja de salir en `GET /api/v1/app/telemetry/fleet/live`).
 *
 * Un camión NO se quita por tiempo sin reportar: su última posición conocida sigue siendo útil. Solo la baja.
 *
 * Canales (privados; los mismos del tema `fleet` que usa `fleet.position`):
 *  - `private-fleet.all`             : quien ve todas las ciudades.
 *  - `private-fleet.city.<ciudad>`   : el personal de la ciudad del camión (departamento de su nodo base).
 *                                      Solo se suma si la ciudad se pudo resolver.
 *
 * Se difunde por la COLA (`ShouldBroadcast`), con un solo intento: si el servidor de sockets no está, el
 * aviso se pierde y la app se corrige sola en su próximo refresco completo de `fleet/live`.
 */
final class FleetVehicleRemoved implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;

    /** Nombre del evento en el cliente (desacoplado del FQCN). */
    public const string NAME = 'fleet.removed';

    /** Intentos del trabajo de difusión: uno, es best-effort. */
    public int $tries = 1;

    /** Tope en segundos del trabajo de difusión. */
    public int $timeout = 15;

    /**
     * @param string      $vehicleId id del camión dado de baja
     * @param string|null $city      departamento de su nodo base, o null si no se pudo resolver
     */
    public function __construct(
        public readonly string $vehicleId,
        public readonly ?string $city = null,
    ) {}

    /**
     * Canales del tema `fleet` según la ciudad del camión.
     *
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return array_map(
            static fn (string $name): PrivateChannel => new PrivateChannel($name),
            RealtimeChannels::forEvent(RealtimeChannels::TOPIC_FLEET, $this->city),
        );
    }

    public function broadcastAs(): string
    {
        return self::NAME;
    }

    /**
     * Solo el id del camión: la app no necesita más para quitarlo.
     *
     * @return array{vehicle_id: string}
     */
    public function broadcastWith(): array
    {
        return ['vehicle_id' => $this->vehicleId];
    }

    public function broadcastQueue(): string
    {
        return LiveBroadcastQueue::name();
    }
}
