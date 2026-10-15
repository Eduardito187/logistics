<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Application\ReadModel;

/**
 * Proyección de lectura del costo de mantenimiento de un vehículo en un rango de fechas: cantidad de
 * órdenes completadas y costo total acumulado (centavos + moneda).
 */
final readonly class MaintenanceCostView
{
    private function __construct(
        public string $vehicleId,
        public string $from,
        public string $to,
        public int $orderCount,
        public int $totalCostCents,
        public string $currency,
    ) {}

    public static function of(
        string $vehicleId,
        string $from,
        string $to,
        int $orderCount,
        int $totalCostCents,
        string $currency,
    ): self {
        return new self($vehicleId, $from, $to, $orderCount, $totalCostCents, $currency);
    }

    /**
     * @return array{vehicle_id: string, from: string, to: string, order_count: int, total_cost_cents: int, currency: string}
     */
    public function toArray(): array
    {
        return [
            'vehicle_id' => $this->vehicleId,
            'from' => $this->from,
            'to' => $this->to,
            'order_count' => $this->orderCount,
            'total_cost_cents' => $this->totalCostCents,
            'currency' => $this->currency,
        ];
    }
}
