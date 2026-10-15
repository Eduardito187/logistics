<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Application\ReadModel;

/**
 * Línea de costo de un corredor: salidas, km, entregas y costo atribuido, con el costo por km y por
 * entrega. El costo se atribuye como km × costo por km del vehículo (ver CorridorCostCalculator).
 */
final readonly class CorridorCostLineView
{
    public function __construct(
        public string $corridor,
        public int $departures,
        public int $kilometers,
        public int $deliveries,
        public int $costCents,
        public ?int $costPerKmCents,
        public ?int $costPerDeliveryCents,
        public string $currency,
    ) {}

    /**
     * @return array{corridor: string, departures: int, kilometers: int, deliveries: int, cost_cents: int, cost_per_km_cents: int|null, cost_per_delivery_cents: int|null, currency: string}
     */
    public function toArray(): array
    {
        return [
            'corridor' => $this->corridor,
            'departures' => $this->departures,
            'kilometers' => $this->kilometers,
            'deliveries' => $this->deliveries,
            'cost_cents' => $this->costCents,
            'cost_per_km_cents' => $this->costPerKmCents,
            'cost_per_delivery_cents' => $this->costPerDeliveryCents,
            'currency' => $this->currency,
        ];
    }
}
