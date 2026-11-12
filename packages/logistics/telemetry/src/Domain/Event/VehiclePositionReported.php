<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * Un lote de telemetría avanzó la posición en vivo de un vehículo que lleva envíos a bordo.
 *
 * Es el puente hacia el seguimiento POR ENVÍO (contexto tracking): lleva la última posición del lote y los
 * envíos cargados en el vehículo, para que un reactor del outbox actualice la posición del camión de cada
 * envío (equivalente a `POST /app/tracking/shipments/{id}/position`) sin que la app tenga que mandar un POST
 * por lectura. Volumen acotado: UNO por lote (no por ping) y solo si hay envíos a bordo.
 *
 * El agregado es el vehículo (tipo 'telemetry.vehicle').
 */
final readonly class VehiclePositionReported extends BaseDomainEvent
{
    /**
     * @param list<string> $shipmentIds envíos a bordo (cargados / en camino) al momento del lote
     */
    public function __construct(
        string $vehicleId,
        public float $lat,
        public float $lng,
        public ?float $speedKph,
        public string $reportedAtIso,
        public array $shipmentIds,
    ) {
        parent::__construct($vehicleId);
    }

    public function eventName(): string
    {
        return 'telemetry.vehicle_position_reported';
    }

    public function payload(): array
    {
        return [
            'vehicle_id' => $this->aggregateId,
            'lat' => $this->lat,
            'lng' => $this->lng,
            'speed_kph' => $this->speedKph,
            'reported_at' => $this->reportedAtIso,
            'shipment_ids' => $this->shipmentIds,
        ];
    }
}
