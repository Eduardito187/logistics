<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * Se registró una carga de combustible de un vehículo de la flota propia (litros, costo y odómetro).
 */
final readonly class RefuelRecorded extends BaseDomainEvent
{
    public function __construct(
        string $aggregateId,
        public string $vehicleId,
        public string $fuelType,
        public float $liters,
        public int $totalCostCents,
        public string $currency,
        public ?int $odometerKm,
        public string $refueledAt,
    ) {
        parent::__construct($aggregateId);
    }

    public function eventName(): string
    {
        return 'fleetops.refuel_recorded';
    }

    public function payload(): array
    {
        return [
            'vehicle_id' => $this->vehicleId,
            'fuel_type' => $this->fuelType,
            'liters' => $this->liters,
            'total_cost_cents' => $this->totalCostCents,
            'currency' => $this->currency,
            'odometer_km' => $this->odometerKm,
            'refueled_at' => $this->refueledAt,
        ];
    }
}
