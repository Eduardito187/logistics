<?php

declare(strict_types=1);

namespace Logistics\Carriers\Infrastructure\Persistence\Eloquent\Mapper;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Logistics\Carriers\Domain\Model\CarrierTrip;
use Logistics\Carriers\Domain\ValueObject\CarrierTripId;
use Logistics\Carriers\Domain\ValueObject\CarrierTripStatus;
use Logistics\Carriers\Infrastructure\Persistence\Eloquent\Model\CarrierTripRecord;

/**
 * Traduce entre el agregado CarrierTrip (dominio) y su fila Eloquent + ids de su carga.
 */
final class CarrierTripMapper
{
    /**
     * @param list<string> $shipmentIds
     */
    public function toDomain(CarrierTripRecord $record, array $shipmentIds): CarrierTrip
    {
        return CarrierTrip::reconstitute(
            CarrierTripId::fromString($record->id),
            $record->carrier_id,
            $record->corridor_from,
            $record->corridor_to,
            self::immutable($record->scheduled_at) ?? new DateTimeImmutable,
            $record->reference,
            CarrierTripStatus::fromLoose($record->status),
            $shipmentIds,
            self::immutable($record->started_at),
            self::immutable($record->closed_at),
            self::immutable($record->cancelled_at),
            $record->started_by,
            $record->closed_by,
        );
    }

    /**
     * @return array{id: string, carrier_id: string, corridor_from: string, corridor_to: string, reference: string|null, status: string, scheduled_at: DateTimeImmutable, started_at: DateTimeImmutable|null, closed_at: DateTimeImmutable|null, cancelled_at: DateTimeImmutable|null, started_by: string|null, closed_by: string|null}
     */
    public function toRow(CarrierTrip $trip): array
    {
        $tz = self::appTimezone();

        return [
            'id' => $trip->id()->toString(),
            'carrier_id' => $trip->carrierId(),
            'corridor_from' => $trip->corridorFrom(),
            'corridor_to' => $trip->corridorTo(),
            'reference' => $trip->reference(),
            'status' => $trip->status()->value,
            'scheduled_at' => $trip->scheduledAt()->setTimezone($tz),
            'started_at' => $trip->startedAt()?->setTimezone($tz),
            'closed_at' => $trip->closedAt()?->setTimezone($tz),
            'cancelled_at' => $trip->cancelledAt()?->setTimezone($tz),
            'started_by' => $trip->startedBy(),
            'closed_by' => $trip->closedBy(),
        ];
    }

    /**
     * Zona horaria de la app. Cada instante se lleva a ella antes de persistir porque Eloquent formatea
     * la fecha SIN offset ('Y-m-d H:i:s'): una fecha con otra zona (p. ej. -04:00 que manda la app) se
     * guardaría corrida. Convertir conserva el instante exacto.
     */
    private static function appTimezone(): DateTimeZone
    {
        return new DateTimeZone(date_default_timezone_get());
    }

    /**
     * Normaliza la fecha del cast (CarbonImmutable) a DateTimeImmutable puro para el dominio.
     */
    private static function immutable(?DateTimeInterface $value): ?DateTimeImmutable
    {
        return $value === null ? null : DateTimeImmutable::createFromInterface($value);
    }
}
