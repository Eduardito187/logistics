<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Infrastructure\Persistence\Eloquent\Mapper;

use DateTimeImmutable;
use Logistics\FleetOps\Domain\Model\FuelPrice;
use Logistics\FleetOps\Domain\ValueObject\FuelPriceId;
use Logistics\FleetOps\Domain\ValueObject\FuelType;
use Logistics\FleetOps\Domain\ValueObject\Money;
use Logistics\FleetOps\Infrastructure\Persistence\Eloquent\Model\FuelPriceRecord;

/**
 * Traduce entre el agregado FuelPrice (dominio) y su fila Eloquent. Único punto que conoce ambos.
 */
final class FuelPriceMapper
{
    public function toDomain(FuelPriceRecord $record): FuelPrice
    {
        return FuelPrice::reconstitute(
            FuelPriceId::fromString($record->id),
            FuelType::from($record->fuel_type),
            Money::of($record->price_cents, $record->currency),
            new DateTimeImmutable($record->effective_from),
            $record->source,
            (bool) $record->active,
        );
    }

    /**
     * @return array{id: string, fuel_type: string, price_cents: int, currency: string, effective_from: string, source: string|null, active: bool}
     */
    public function toRow(FuelPrice $price): array
    {
        return [
            'id' => $price->id()->toString(),
            'fuel_type' => $price->fuelType()->value,
            'price_cents' => $price->pricePerLiter()->cents,
            'currency' => $price->pricePerLiter()->currency,
            'effective_from' => $price->effectiveFrom()->format('Y-m-d'),
            'source' => $price->source(),
            'active' => $price->isActive(),
        ];
    }
}
