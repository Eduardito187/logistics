<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Application\ReadModel;

/**
 * Proyección del consumo de combustible de un vehículo en un rango: litros y costo totales, km recorridos
 * entre llenados a tanque lleno consecutivos, rendimiento (km/l) y costo por km.
 *
 * `kmPerLiter` y `costPerKmCents` son nulos cuando no hay al menos dos cargas a tanque lleno con odómetro
 * en el rango (sin dos referencias no se puede medir el consumo real).
 */
final readonly class FuelConsumptionView
{
    public function __construct(
        public string $vehicleId,
        public string $from,
        public string $to,
        public float $totalLiters,
        public int $totalCostCents,
        public string $currency,
        public int $kmTraveled,
        public ?float $kmPerLiter,
        public ?int $costPerKmCents,
    ) {}

    /**
     * @return array{vehicle_id: string, from: string, to: string, total_liters: float, total_cost_cents: int, currency: string, km_traveled: int, km_per_liter: float|null, cost_per_km_cents: int|null}
     */
    public function toArray(): array
    {
        return [
            'vehicle_id' => $this->vehicleId,
            'from' => $this->from,
            'to' => $this->to,
            'total_liters' => $this->totalLiters,
            'total_cost_cents' => $this->totalCostCents,
            'currency' => $this->currency,
            'km_traveled' => $this->kmTraveled,
            'km_per_liter' => $this->kmPerLiter,
            'cost_per_km_cents' => $this->costPerKmCents,
        ];
    }
}
