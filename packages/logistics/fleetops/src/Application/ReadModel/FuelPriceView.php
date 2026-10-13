<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Application\ReadModel;

use Logistics\FleetOps\Domain\Model\FuelPrice;

/**
 * Proyección de lectura de un precio de combustible para listados y API. Primitivos: no expone objetos
 * de dominio. El dinero viaja como centavos + moneda.
 */
final readonly class FuelPriceView
{
    private function __construct(
        public string $id,
        public string $fuelType,
        public int $priceCents,
        public string $currency,
        public string $effectiveFrom,
        public ?string $source,
    ) {}

    public static function fromDomain(FuelPrice $price): self
    {
        return new self(
            $price->id()->toString(),
            $price->fuelType()->value,
            $price->pricePerLiter()->cents,
            $price->pricePerLiter()->currency,
            $price->effectiveFrom()->format('Y-m-d'),
            $price->source(),
        );
    }

    /**
     * @return array{id: string, fuel_type: string, price_cents: int, currency: string, effective_from: string, source: string|null}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'fuel_type' => $this->fuelType,
            'price_cents' => $this->priceCents,
            'currency' => $this->currency,
            'effective_from' => $this->effectiveFrom,
            'source' => $this->source,
        ];
    }
}
