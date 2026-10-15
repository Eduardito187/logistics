<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Application\ReadModel;

/**
 * Línea de rentabilidad de un corredor: costo atribuido, ingreso (OPCIONAL) y margen = ingreso − costo.
 *
 * El ingreso y el margen son nullable a propósito (ROADMAP 3-06): mientras no exista facturación el
 * ingreso llega por configuración (settings/env) o no llega. Si no hay ingreso, margen y ratio son null:
 * el read-model queda listo para enchufar el ingreso real sin cambiar su contrato.
 */
final readonly class CorridorProfitabilityLineView
{
    public function __construct(
        public string $corridor,
        public int $deliveries,
        public int $costCents,
        public ?int $revenueCents,
        public ?int $marginCents,
        public ?float $marginRatio,
        public string $currency,
    ) {}

    /**
     * @return array{corridor: string, deliveries: int, cost_cents: int, revenue_cents: int|null, margin_cents: int|null, margin_ratio: float|null, currency: string}
     */
    public function toArray(): array
    {
        return [
            'corridor' => $this->corridor,
            'deliveries' => $this->deliveries,
            'cost_cents' => $this->costCents,
            'revenue_cents' => $this->revenueCents,
            'margin_cents' => $this->marginCents,
            'margin_ratio' => $this->marginRatio,
            'currency' => $this->currency,
        ];
    }
}
