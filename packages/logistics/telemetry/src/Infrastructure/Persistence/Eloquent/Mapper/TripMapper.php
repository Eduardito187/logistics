<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Infrastructure\Persistence\Eloquent\Mapper;

use DateTimeImmutable;
use Logistics\Telemetry\Domain\Model\Trip;
use Logistics\Telemetry\Domain\ValueObject\GeoPoint;
use Logistics\Telemetry\Domain\ValueObject\TripId;
use Logistics\Telemetry\Domain\ValueObject\TripStatus;
use Logistics\Telemetry\Infrastructure\Persistence\Eloquent\Model\TripRecord;

/**
 * Traduce entre el agregado Trip (dominio) y su fila Eloquent. Único punto que conoce ambos.
 */
final class TripMapper
{
    public function toDomain(TripRecord $record): Trip
    {
        $endPoint = $record->end_lat === null || $record->end_lng === null
            ? null
            : GeoPoint::of($record->end_lat, $record->end_lng);

        return Trip::reconstitute(
            TripId::fromString($record->id),
            $record->vehicle_id,
            $record->driver_id,
            $record->route_id,
            TripStatus::from($record->status),
            $record->started_at->toDateTimeImmutable(),
            $record->ended_at !== null ? $record->ended_at->toDateTimeImmutable() : null,
            GeoPoint::of($record->start_lat, $record->start_lng),
            $endPoint,
            $record->distance_m,
            $record->duration_s,
            $record->moving_s,
            $record->idle_s,
            $record->max_speed_kph,
            $record->ping_count,
        );
    }

}
