<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Presentation\Broadcasting;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Evento de BROADCAST: una posición de vehículo nueva para difundir en vivo a los espectadores.
 *
 * Implementa el contrato del framework `ShouldBroadcast` (ya disponible sin Reverb instalado). El
 * servidor de WebSockets (Laravel Reverb, self-host — ADR 016) lo empuja a los navegadores suscritos vía
 * Laravel Echo. Mientras no esté `BROADCAST_CONNECTION=reverb`, el evento se emite al conector `log`
 * (no rompe nada); el fan-out real lo habilita el orquestador al configurar Reverb/Echo.
 *
 * Payload PRIMITIVO (nunca objetos de dominio): vehicle_id, plate (placa legible), lat, lng, status y
 * occurred_at. La placa se resuelve en la ingesta (lectura cross-context read-only, en lote) para que el
 * cliente no tenga que cruzar contextos.
 *
 * Canales:
 *  - `fleet`          : canal del panel de la torre (gated a Admin/Supervisor en routes/channels.php).
 *  - `vehicle.{id}`   : canal por vehículo (seguimiento de una unidad concreta).
 *
 * Al difundirse por queue (ShouldBroadcast), el fan-out no bloquea la ingesta ni hace I/O síncrono.
 */
final class VehiclePositionUpdated implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public readonly string $vehicleId,
        public readonly ?string $plate,
        public readonly float $lat,
        public readonly float $lng,
        public readonly string $status,
        public readonly string $occurredAt,
    ) {}

    /**
     * Canales a los que se difunde: el público del panel (`fleet`) y el privado del vehículo.
     *
     * @return list<Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new Channel('fleet'),
            new PrivateChannel('vehicle.'.$this->vehicleId),
        ];
    }

    /**
     * Nombre estable del evento en el cliente (Echo escucha `.vehicle.position`), desacoplado del FQCN.
     */
    public function broadcastAs(): string
    {
        return 'vehicle.position';
    }

    /**
     * Carga útil primitiva que viaja al navegador.
     *
     * @return array{vehicle_id: string, plate: string|null, lat: float, lng: float, status: string, occurred_at: string}
     */
    public function broadcastWith(): array
    {
        return [
            'vehicle_id' => $this->vehicleId,
            'plate' => $this->plate,
            'lat' => $this->lat,
            'lng' => $this->lng,
            'status' => $this->status,
            'occurred_at' => $this->occurredAt,
        ];
    }
}
