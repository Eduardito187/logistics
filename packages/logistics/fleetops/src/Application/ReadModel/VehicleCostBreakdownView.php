<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Application\ReadModel;

/**
 * Desglose de costo de la flota por vehículo en un período. Proyección agregada (no mapea 1:1 a un
 * agregado): la arma el QueryHandler sumando los repos de combustible/mantenimiento/gastos por vehículo.
 */
final readonly class VehicleCostBreakdownView
{
    /**
     * @param list<VehicleCostLineView> $lines
     */
    public function __construct(
        public string $from,
        public string $to,
        public int $totalCents,
        public string $currency,
        public array $lines,
    ) {}

    /**
     * @return array{from: string, to: string, total_cents: int, currency: string, lines: list<array{vehicle_id: string, fuel_cents: int, maintenance_cents: int, expenses_cents: int, total_cents: int, kilometers: int, cost_per_km_cents: int|null, currency: string}>}
     */
    public function toArray(): array
    {
        return [
            'from' => $this->from,
            'to' => $this->to,
            'total_cents' => $this->totalCents,
            'currency' => $this->currency,
            'lines' => array_map(static fn (VehicleCostLineView $line): array => $line->toArray(), $this->lines),
        ];
    }
}
