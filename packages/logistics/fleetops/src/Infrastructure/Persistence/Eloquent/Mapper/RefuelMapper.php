<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Infrastructure\Persistence\Eloquent\Mapper;

use Logistics\FleetOps\Domain\Model\Refuel;
use Logistics\FleetOps\Domain\ValueObject\FuelType;
use Logistics\FleetOps\Domain\ValueObject\Money;
use Logistics\FleetOps\Domain\ValueObject\Odometer;
use Logistics\FleetOps\Domain\ValueObject\RefuelId;
use Logistics\FleetOps\Infrastructure\Persistence\Eloquent\Model\RefuelRecord;

/**
 * Traduce entre el agregado Refuel (dominio) y su fila Eloquent. Único punto que conoce ambos.
 */
final class RefuelMapper
{
    public function toDomain(RefuelRecord $record): Refuel
    {
        return Refuel::reconstitute(
            RefuelId::fromString($record->id),
            $record->vehicle_id,
            $record->driver_id,
            FuelType::from($record->fuel_type),
            $record->liters,
            Money::of($record->price_per_liter_cents, $record->currency),
            Money::of($record->total_cost_cents, $record->currency),
            $record->odometer_km !== null ? Odometer::km($record->odometer_km) : null,
            $record->station,
            $record->full_tank,
            // La columna está casteada a datetime: Eloquent la entrega como Carbon → a DateTimeImmutable.
            $record->refueled_at->toDateTimeImmutable(),
            $record->note,
        );
    }

    /**
     * @return array{id: string, vehicle_id: string, driver_id: string|null, fuel_type: string, liters: float, price_per_liter_cents: int, currency: string, total_cost_cents: int, odometer_km: int|null, station: string|null, full_tank: bool, refueled_at: \DateTimeImmutable, note: string|null}
     */
    public function toRow(Refuel $refuel): array
    {
        return [
            'id' => $refuel->id()->toString(),
            'vehicle_id' => $refuel->vehicleId(),
            'driver_id' => $refuel->driverId(),
            'fuel_type' => $refuel->fuelType()->value,
            'liters' => $refuel->liters(),
            'price_per_liter_cents' => $refuel->pricePerLiter()->cents,
            'currency' => $refuel->totalCost()->currency,
            'total_cost_cents' => $refuel->totalCost()->cents,
            'odometer_km' => $refuel->odometer()?->km,
            'station' => $refuel->station(),
            'full_tank' => $refuel->isFullTank(),
            'refueled_at' => $refuel->refueledAt(),
            'note' => $refuel->note(),
        ];
    }
}
