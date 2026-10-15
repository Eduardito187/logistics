<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Application\ReadModel;

/**
 * Proyección del gasto de combustible de un día: total del día y desglose por vehículo (costo y litros).
 */
final readonly class DailyFuelSpendView
{
    /**
     * @param list<array{vehicle_id: string, total_cost_cents: int, liters: float}> $byVehicle
     */
    public function __construct(
        public string $date,
        public int $totalCostCents,
        public string $currency,
        public array $byVehicle,
    ) {}

    /**
     * @return array{date: string, total_cost_cents: int, currency: string, by_vehicle: list<array{vehicle_id: string, total_cost_cents: int, liters: float}>}
     */
    public function toArray(): array
    {
        return [
            'date' => $this->date,
            'total_cost_cents' => $this->totalCostCents,
            'currency' => $this->currency,
            'by_vehicle' => $this->byVehicle,
        ];
    }
}
