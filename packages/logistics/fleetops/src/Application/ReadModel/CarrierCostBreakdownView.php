<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Application\ReadModel;

/**
 * Desglose de costo de TERCEROS (transportistas) en un período, a partir de las liquidaciones del
 * contexto carriers. Proyección agregada read-only (degrada a lista vacía si carriers no está presente).
 */
final readonly class CarrierCostBreakdownView
{
    /**
     * @param list<CarrierCostLineView> $lines
     */
    public function __construct(
        public string $from,
        public string $to,
        public int $totalCents,
        public string $currency,
        public array $lines,
    ) {}

    /**
     * @return array{from: string, to: string, total_cents: int, currency: string, lines: list<array{carrier_id: string, carrier_name: string|null, settlements: int, corridors: int, cost_cents: int, currency: string}>}
     */
    public function toArray(): array
    {
        return [
            'from' => $this->from,
            'to' => $this->to,
            'total_cents' => $this->totalCents,
            'currency' => $this->currency,
            'lines' => array_map(static fn (CarrierCostLineView $line): array => $line->toArray(), $this->lines),
        ];
    }
}
