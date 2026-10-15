<?php

declare(strict_types=1);

namespace Logistics\Tracking\Infrastructure\Persistence\Eloquent;

use DateTimeImmutable;
use Logistics\Tracking\Domain\Model\UnitTracking;
use Logistics\Tracking\Domain\ValueObject\UnitStatus;
use Logistics\Tracking\Domain\ValueObject\UnitTrackingId;

/**
 * Traduce entre el agregado UnitTracking y su fila Eloquent. Toda la conversión a/desde primitivos
 * vive acá, para que ni el dominio ni el repositorio conozcan la forma de la tabla.
 */
final class UnitTrackingMapper
{
    /**
     * @return array<string, mixed>
     */
    public function toRow(UnitTracking $unit): array
    {
        return [
            'id' => $unit->id()->toString(),
            'shipment_id' => $unit->shipmentId(),
            'unit_id' => $unit->unitId(),
            'status' => $unit->status()->value,
            'last_location_id' => $unit->lastLocationId(),
            'last_seen_at' => $unit->lastSeenAt()?->format(DateTimeImmutable::ATOM),
        ];
    }

    /**
     * Reconstruye el agregado desde su fila. `last_seen_at` está casteado a Carbon en el Record: se
     * pasa a DateTimeImmutable con toDateTimeImmutable() preservando la zona horaria.
     */
    public function toDomain(UnitTrackingRecord $record): UnitTracking
    {
        return UnitTracking::reconstitute(
            UnitTrackingId::fromString($record->id),
            $record->shipment_id,
            $record->unit_id,
            UnitStatus::from($record->status),
            $record->last_location_id,
            $record->last_seen_at?->toDateTimeImmutable(),
        );
    }
}
