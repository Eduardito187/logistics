<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * Se registró la salida/despacho de un vehículo de flota propia (quedó en ruta).
 */
final readonly class DepartureRegistered extends BaseDomainEvent
{
    public function __construct(
        string $aggregateId,
        public string $vehicleId,
        public ?string $driverId,
        // Momento de salida en ISO 8601; distinto de `occurredAt` del evento base (cuándo se creó el evento).
        public string $departedAtIso,
    ) {
        parent::__construct($aggregateId);
    }

    public function eventName(): string
    {
        return 'fleetops.departure_registered';
    }

    public function payload(): array
    {
        return [
            'vehicle_id' => $this->vehicleId,
            'driver_id' => $this->driverId,
            'departed_at' => $this->departedAtIso,
        ];
    }
}
