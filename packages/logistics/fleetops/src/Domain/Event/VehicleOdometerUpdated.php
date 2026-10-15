<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * Se actualizó el odómetro vigente del perfil de un vehículo (el odómetro solo avanza).
 */
final readonly class VehicleOdometerUpdated extends BaseDomainEvent
{
    public function __construct(
        string $aggregateId,
        public string $vehicleId,
        public int $currentOdometerKm,
    ) {
        parent::__construct($aggregateId);
    }

    public function eventName(): string
    {
        return 'fleetops.vehicle_odometer_updated';
    }

    public function payload(): array
    {
        return [
            'vehicle_id' => $this->vehicleId,
            'current_odometer_km' => $this->currentOdometerKm,
        ];
    }
}
