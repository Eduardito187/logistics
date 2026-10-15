<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * Se agendó una orden de mantenimiento para un vehículo de la flota propia.
 */
final readonly class MaintenanceScheduled extends BaseDomainEvent
{
    public function __construct(
        string $aggregateId,
        public string $vehicleId,
        public string $type,
        public ?int $odometerKm,
        public string $scheduledAt,
    ) {
        parent::__construct($aggregateId);
    }

    public function eventName(): string
    {
        return 'fleetops.maintenance_scheduled';
    }

    public function payload(): array
    {
        return [
            'vehicle_id' => $this->vehicleId,
            'type' => $this->type,
            'odometer_km' => $this->odometerKm,
            'scheduled_at' => $this->scheduledAt,
        ];
    }
}
