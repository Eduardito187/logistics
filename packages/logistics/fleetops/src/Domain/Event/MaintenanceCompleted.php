<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * Una orden de mantenimiento se completó: trae el costo total (centavos + moneda) y el odómetro al cierre.
 */
final readonly class MaintenanceCompleted extends BaseDomainEvent
{
    public function __construct(
        string $aggregateId,
        public int $totalCostCents,
        public string $currency,
        public ?int $odometerKm,
        public string $completedAt,
    ) {
        parent::__construct($aggregateId);
    }

    public function eventName(): string
    {
        return 'fleetops.maintenance_completed';
    }

    public function payload(): array
    {
        return [
            'total_cost_cents' => $this->totalCostCents,
            'currency' => $this->currency,
            'odometer_km' => $this->odometerKm,
            'completed_at' => $this->completedAt,
        ];
    }
}
