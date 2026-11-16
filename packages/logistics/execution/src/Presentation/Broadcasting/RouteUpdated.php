<?php

declare(strict_types=1);

namespace Logistics\Execution\Presentation\Broadcasting;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Evento de BROADCAST: cambió la hoja de ruta de un camión (`GET /app/vehicles/{id}/route`).
 *
 * Viaja por el canal privado de cada persona de la tripulación (`private-user.{id}`, autorizado en
 * routes/channels.php solo al propio usuario) con el nombre estable `route.updated`. Es solo la SEÑAL para
 * que la app del chofer vuelva a pedir su ruta: el contenido (paradas, estados, ETAs) nunca viaja por el
 * socket.
 *
 * Carga: `{"route_id": "<uuid>"|null, "vehicle_id": "<uuid>"}`. `route_id` va en null cuando el cambio es del
 * camión y todavía no tiene una ruta armada para ese día (p. ej. recién se le asignó la tripulación o un
 * pedido): la app igual refresca lo que muestra de ese vehículo.
 *
 * Un mismo evento lleva varios canales (chofer y ayudantes): el transporte Pusher los publica en una sola
 * llamada. Se difunde por la COLA (`ShouldBroadcast`, nunca `ShouldBroadcastNow`) y con un solo intento:
 * si el servidor de sockets no está, la señal se pierde sin reintentos ni ruido (la app sigue teniendo su
 * refresco por tiempo como respaldo).
 */
final class RouteUpdated implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;

    /** Nombre del evento en el cliente (desacoplado del FQCN). */
    public const NAME = 'route.updated';

    /** Tope de canales por evento (límite de canales por publicación del protocolo Pusher). */
    public const MAX_CHANNELS = 100;

    /** Intentos del trabajo de difusión: uno, es best-effort. */
    public int $tries = 1;

    /** Tope en segundos del trabajo de difusión. */
    public int $timeout = 15;

    /**
     * @param list<int> $userIds Destinatarios (ids enteros de usuario); a lo sumo MAX_CHANNELS.
     */
    public function __construct(
        public readonly array $userIds,
        public readonly ?string $routeId,
        public readonly string $vehicleId,
    ) {}

    /**
     * Nombre del canal SIN el prefijo `private-` (el que se declara en routes/channels.php).
     */
    public static function channelName(int $userId): string
    {
        return 'user.'.$userId;
    }

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        $channels = [];
        foreach (array_slice($this->userIds, 0, self::MAX_CHANNELS) as $userId) {
            if ($userId > 0) {
                $channels[] = new PrivateChannel(self::channelName($userId));
            }
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return self::NAME;
    }

    /**
     * @return array{route_id: string|null, vehicle_id: string}
     */
    public function broadcastWith(): array
    {
        return ['route_id' => $this->routeId, 'vehicle_id' => $this->vehicleId];
    }

    /**
     * Cola del trabajo de difusión: la misma de los avisos (su nombre real sale de la config).
     */
    public function broadcastQueue(): string
    {
        return (string) config('observability.metrics.queues.notifications', 'notifications');
    }
}
