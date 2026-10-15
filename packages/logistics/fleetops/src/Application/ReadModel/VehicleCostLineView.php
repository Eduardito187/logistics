<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Application\ReadModel;

/**
 * Línea de costo de un vehículo dentro del desglose de flota: combustible + mantenimiento + gastos =
 * costo total, y el costo por km del período. Dinero en centavos + moneda.
 */
final readonly class VehicleCostLineView
{
    public function __construct(
        public string $vehicleId,
        public int $fuelCents,
        public int $maintenanceCents,
        public int $expensesCents,
        public int $totalCents,
        public int $kilometers,
        public ?int $costPerKmCents,
        public string $currency,
    ) {}

    /**
     * @return array{vehicle_id: string, fuel_cents: int, maintenance_cents: int, expenses_cents: int, total_cents: int, kilometers: int, cost_per_km_cents: int|null, currency: string}
     */
    public function toArray(): array
    {
        return [
            'vehicle_id' => $this->vehicleId,
            'fuel_cents' => $this->fuelCents,
            'maintenance_cents' => $this->maintenanceCents,
            'expenses_cents' => $this->expensesCents,
            'total_cents' => $this->totalCents,
            'kilometers' => $this->kilometers,
            'cost_per_km_cents' => $this->costPerKmCents,
            'currency' => $this->currency,
        ];
    }
}
