<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * Se definió (o re-definió) el perfil técnico de un vehículo de la flota propia.
 */
final readonly class VehicleProfileDefined extends BaseDomainEvent
{
    public function __construct(
        string $aggregateId,
        public string $vehicleId,
        public ?string $brand,
        public ?string $model,
        public ?int $year,
        public ?string $fuelType,
    ) {
        parent::__construct($aggregateId);
    }

    public function eventName(): string
    {
        return 'fleetops.vehicle_profile_defined';
    }

    public function payload(): array
    {
        return [
            'vehicle_id' => $this->vehicleId,
            'brand' => $this->brand,
            'model' => $this->model,
            'year' => $this->year,
            'fuel_type' => $this->fuelType,
        ];
    }
}
