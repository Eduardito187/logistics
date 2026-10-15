<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * Se definió un plan de mantenimiento preventivo para un vehículo (por km y/o por días).
 */
final readonly class MaintenancePlanDefined extends BaseDomainEvent
{
    public function __construct(
        string $aggregateId,
        public string $vehicleId,
        public string $serviceKind,
        public ?int $intervalKm,
        public ?int $intervalDays,
    ) {
        parent::__construct($aggregateId);
    }

    public function eventName(): string
    {
        return 'fleetops.maintenance_plan_defined';
    }

    public function payload(): array
    {
        return [
            'vehicle_id' => $this->vehicleId,
            'service_kind' => $this->serviceKind,
            'interval_km' => $this->intervalKm,
            'interval_days' => $this->intervalDays,
        ];
    }
}
