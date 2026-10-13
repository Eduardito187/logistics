<?php

declare(strict_types=1);

namespace Logistics\Carriers\Infrastructure\Persistence\Eloquent\Mapper;

use DateTimeImmutable;
use Logistics\Carriers\Domain\Model\Tariff;
use Logistics\Carriers\Domain\ValueObject\CorridorRef;
use Logistics\Carriers\Domain\ValueObject\Money;
use Logistics\Carriers\Domain\ValueObject\TariffId;
use Logistics\Carriers\Domain\ValueObject\VehicleClass;
use Logistics\Carriers\Infrastructure\Persistence\Eloquent\Model\TariffRecord;

/**
 * Traduce entre el agregado Tariff (dominio) y su fila Eloquent. Único punto que conoce ambos.
 */
final class TariffMapper
{
    public function toDomain(TariffRecord $record): Tariff
    {
        return Tariff::reconstitute(
            TariffId::fromString($record->id),
            $record->carrier_id,
            CorridorRef::of($record->corridor_from, $record->corridor_to, $record->corridor_type),
            VehicleClass::from($record->vehicle_class),
            Money::of($record->price_cents, $record->currency),
            new DateTimeImmutable($record->effective_from),
        );
    }

    /**
     * @return array{id: string, carrier_id: string, corridor_from: string, corridor_to: string, corridor_type: string, vehicle_class: string, price_cents: int, currency: string, effective_from: string}
     */
    public function toRow(Tariff $tariff): array
    {
        return [
            'id' => $tariff->id()->toString(),
            'carrier_id' => $tariff->carrierId(),
            'corridor_from' => $tariff->corridor()->from,
            'corridor_to' => $tariff->corridor()->to,
            'corridor_type' => $tariff->corridor()->type,
            'vehicle_class' => $tariff->vehicleClass()->value,
            'price_cents' => $tariff->price()->cents,
            'currency' => $tariff->price()->currency,
            'effective_from' => $tariff->effectiveFrom()->format('Y-m-d'),
        ];
    }
}
