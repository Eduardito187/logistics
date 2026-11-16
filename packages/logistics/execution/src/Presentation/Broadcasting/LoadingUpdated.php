<?php

declare(strict_types=1);

namespace Logistics\Execution\Presentation\Broadcasting;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Logistics\Shared\Presentation\Realtime\RealtimeChannels;

/**
 * Evento de BROADCAST: cambió la carga de un camión (lo que se está cargando / la lista de entregas del
 * vehículo). Para la pantalla de carga/andén.
 *
 * Viaja por los canales del tema `loading` que cubren al camión (ver {@see RealtimeChannels}):
 * `loading.all` (oficina), `loading.city.<depto>` (preparador/andén de esa ciudad) y `loading.driver.<uuid>`
 * (el chofer del camión hoy), siempre como canales privados. Es solo la SEÑAL para que la app vuelva a pedir
 * la hoja de carga / las entregas del vehículo: el contenido nunca viaja por el socket.
 *
 * Carga: `{"vehicle_id": "<uuid>", "shipment_id": "<uuid>"|null}`. `shipment_id` en null significa que cambió
 * la lista de entregas del vehículo en general (p. ej. se armó/editó su ruta), sin un envío puntual.
 *
 * Un mismo evento lleva varios canales: el transporte Pusher los publica en una sola llamada. Se difunde por
 * la COLA (`ShouldBroadcast`, nunca `ShouldBroadcastNow`) y con un solo intento: si el servidor de sockets no
 * está, la señal se pierde sin reintentos ni ruido (la app conserva su refresco por tiempo como respaldo).
 */
final class LoadingUpdated implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;

    /** Nombre del evento en el cliente (desacoplado del FQCN). */
    public const NAME = 'loading.updated';

    /** Tope de canales por evento (límite de canales por publicación del protocolo Pusher). */
    public const MAX_CHANNELS = 100;

    /** Intentos del trabajo de difusión: uno, es best-effort. */
    public int $tries = 1;

    /** Tope en segundos del trabajo de difusión. */
    public int $timeout = 15;

    /**
     * @param list<string> $channels Nombres de canal SIN el prefijo `private-` (los arma el reader del tema); a lo sumo MAX_CHANNELS.
     */
    public function __construct(
        public readonly array $channels,
        public readonly string $vehicleId,
        public readonly ?string $shipmentId = null,
    ) {}

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        $channels = [];
        foreach (array_slice($this->channels, 0, self::MAX_CHANNELS) as $channel) {
            if ($channel !== '') {
                $channels[] = new PrivateChannel($channel);
            }
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return self::NAME;
    }

    /**
     * @return array{vehicle_id: string, shipment_id: string|null}
     */
    public function broadcastWith(): array
    {
        return ['vehicle_id' => $this->vehicleId, 'shipment_id' => $this->shipmentId];
    }

    /**
     * Cola del trabajo de difusión: la misma de los avisos (su nombre real sale de la config).
     */
    public function broadcastQueue(): string
    {
        return (string) config('observability.metrics.queues.notifications', 'notifications');
    }
}
