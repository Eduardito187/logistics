<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Application\ReadModel;

/**
 * Desglose de costo de la flota propia por corredor en un período.
 */
final readonly class CorridorCostBreakdownView
{
    /**
     * @param list<CorridorCostLineView> $lines
     */
    public function __construct(
        public string $from,
        public string $to,
        public int $totalCents,
        public string $currency,
        public array $lines,
    ) {}

    /**
     * @return array{from: string, to: string, total_cents: int, currency: string, lines: list<array{corridor: string, departures: int, kilometers: int, deliveries: int, cost_cents: int, cost_per_km_cents: int|null, cost_per_delivery_cents: int|null, currency: string}>}
     */
    public function toArray(): array
    {
        return [
            'from' => $this->from,
            'to' => $this->to,
            'total_cents' => $this->totalCents,
            'currency' => $this->currency,
            'lines' => array_map(static fn (CorridorCostLineView $line): array => $line->toArray(), $this->lines),
        ];
    }
}
