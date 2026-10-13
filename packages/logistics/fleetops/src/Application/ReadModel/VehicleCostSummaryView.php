<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Application\ReadModel;

/**
 * Resumen de costos de un vehículo en un período: combustible + mantenimiento + gastos = costo total
 * de operación (TCO), y el costo por kilómetro usando los km recorridos (de telemetría).
 *
 * Proyección agregada (no mapea 1:1 a un agregado); la arma el QueryHandler combinando varios repos.
 * Dinero en centavos + moneda.
 */
final readonly class VehicleCostSummaryView
{
    public function __construct(
        public string $vehicleId,
        public string $from,
        public string $to,
        public int $fuelCents,
        public int $maintenanceCents,
        public int $expensesCents,
        public int $totalCents,
        public string $currency,
        public int $kilometers,
        public ?int $costPerKmCents,
    ) {}

    /**
     * @return array{vehicle_id: string, from: string, to: string, fuel_cents: int, maintenance_cents: int, expenses_cents: int, total_cents: int, currency: string, kilometers: int, cost_per_km_cents: int|null}
     */
    public function toArray(): array
    {
        return [
            'vehicle_id' => $this->vehicleId,
            'from' => $this->from,
            'to' => $this->to,
            'fuel_cents' => $this->fuelCents,
            'maintenance_cents' => $this->maintenanceCents,
            'expenses_cents' => $this->expensesCents,
            'total_cents' => $this->totalCents,
            'currency' => $this->currency,
            'kilometers' => $this->kilometers,
            'cost_per_km_cents' => $this->costPerKmCents,
        ];
    }
}
