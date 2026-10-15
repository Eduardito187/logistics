<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Application\ReadModel;

/**
 * Rentabilidad de la flota por corredor en un período = ingreso − costo. El ingreso es opcional: los
 * totales de ingreso/margen son null si no se proveyó ningún ingreso (no se inventa ingreso).
 */
final readonly class CorridorProfitabilityView
{
    /**
     * @param list<CorridorProfitabilityLineView> $lines
     */
    public function __construct(
        public string $from,
        public string $to,
        public int $totalCostCents,
        public ?int $totalRevenueCents,
        public ?int $totalMarginCents,
        public string $currency,
        public bool $revenueAvailable,
        public array $lines,
    ) {}

    /**
     * @return array{from: string, to: string, total_cost_cents: int, total_revenue_cents: int|null, total_margin_cents: int|null, currency: string, revenue_available: bool, lines: list<array{corridor: string, deliveries: int, cost_cents: int, revenue_cents: int|null, margin_cents: int|null, margin_ratio: float|null, currency: string}>}
     */
    public function toArray(): array
    {
        return [
            'from' => $this->from,
            'to' => $this->to,
            'total_cost_cents' => $this->totalCostCents,
            'total_revenue_cents' => $this->totalRevenueCents,
            'total_margin_cents' => $this->totalMarginCents,
            'currency' => $this->currency,
            'revenue_available' => $this->revenueAvailable,
            'lines' => array_map(
                static fn (CorridorProfitabilityLineView $line): array => $line->toArray(),
                $this->lines,
            ),
        ];
    }
}
