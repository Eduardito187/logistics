<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Infrastructure\Persistence\Eloquent\Mapper;

use DateTimeImmutable;
use Logistics\FleetOps\Domain\Model\OdometerReading;
use Logistics\FleetOps\Domain\ValueObject\Odometer;
use Logistics\FleetOps\Domain\ValueObject\OdometerReadingId;
use Logistics\FleetOps\Domain\ValueObject\OdometerSource;
use Logistics\FleetOps\Infrastructure\Persistence\Eloquent\Model\OdometerReadingRecord;

/**
 * Traduce entre el agregado OdometerReading (dominio) y su fila Eloquent. Único punto que conoce ambos.
 */
final class OdometerReadingMapper
{
    public function toDomain(OdometerReadingRecord $record): OdometerReading
    {
        return OdometerReading::reconstitute(
            OdometerReadingId::fromString($record->id),
            $record->vehicle_id,
            Odometer::km($record->km),
            OdometerSource::from($record->source),
            $record->read_at->toDateTimeImmutable(),
            $record->note,
        );
    }

    /**
     * @return array{id: string, vehicle_id: string, km: int, source: string, read_at: DateTimeImmutable, note: string|null}
     */
    public function toRow(OdometerReading $reading): array
    {
        return [
            'id' => $reading->id()->toString(),
            'vehicle_id' => $reading->vehicleId(),
            'km' => $reading->odometer()->km,
            'source' => $reading->source()->value,
            'read_at' => $reading->readAt(),
            'note' => $reading->note(),
        ];
    }
}
