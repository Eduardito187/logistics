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
 * Carga base: `{"route_id": "<uuid>"|null, "vehicle_id": "<uuid>"}`. `route_id` va en null cuando el cambio es
 * del camión y todavía no tiene una ruta armada para ese día (p. ej. recién se le asignó la tripulación o un
 * pedido): la app igual refresca lo que muestra de ese vehículo.
 *
 * Campos ADITIVOS (opcionales, solo cuando el motor único de ETA respondió): `off_route` (bool, el camión se
 * salió de su ruta planificada), `rerouted` (bool, la ETA se recalculó desde la posición actual hacia la
 * siguiente parada) y `stops` (lista `{shipment_id, eta_at}` con la ETA viva por parada pendiente). La app
 * puede usarlos directo o, como respaldo, re-pedir `GET /app/vehicles/{id}/route`, que trae lo mismo. Cuando
 * el motor no está (telemetría apagada) la carga es la histórica de dos campos, sin romper compatibilidad.
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
     * @param list<int>                                        $userIds Destinatarios (ids enteros de usuario); a lo sumo MAX_CHANNELS.
     * @param list<array{shipment_id: string, eta_at: string}> $stops   ETA viva por parada pendiente (vacío = no se incluye)
     */
    public function __construct(
        public readonly array $userIds,
        public readonly ?string $routeId,
        public readonly string $vehicleId,
        public readonly ?bool $offRoute = null,
        public readonly ?bool $rerouted = null,
        public readonly array $stops = [],
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
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        $payload = ['route_id' => $this->routeId, 'vehicle_id' => $this->vehicleId];

        // Campos aditivos: solo viajan cuando el motor de ETA aportó el estado (compatibilidad hacia atrás).
        if ($this->offRoute !== null) {
            $payload['off_route'] = $this->offRoute;
        }
        if ($this->rerouted !== null) {
            $payload['rerouted'] = $this->rerouted;
        }
        if ($this->stops !== []) {
            $payload['stops'] = $this->stops;
        }

        return $payload;
    }

    /**
     * Cola del trabajo de difusión: la misma de los avisos (su nombre real sale de la config).
     */
    public function broadcastQueue(): string
    {
        return (string) config('observability.metrics.queues.notifications', 'notifications');
    }
}
