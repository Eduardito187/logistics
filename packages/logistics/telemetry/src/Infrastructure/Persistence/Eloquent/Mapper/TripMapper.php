<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Infrastructure\Persistence\Eloquent\Mapper;

use DateTimeImmutable;
use DateTimeZone;
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
            self::wallInstant($record->started_at->toDateTimeImmutable()),
            $record->ended_at !== null ? self::wallInstant($record->ended_at->toDateTimeImmutable()) : null,
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

    /**
     * Fila Eloquent a partir del agregado Trip (para insertar/actualizar).
     *
     * @return array{id: string, vehicle_id: string, driver_id: string|null, route_id: string|null, status: string, started_at: DateTimeImmutable, ended_at: DateTimeImmutable|null, start_lat: float, start_lng: float, end_lat: float|null, end_lng: float|null, distance_m: float, duration_s: int, moving_s: int, idle_s: int, max_speed_kph: float, ping_count: int}
     */
    public function toRow(Trip $trip): array
    {
        $end = $trip->endPoint();

        return [
            'id' => $trip->id()->toString(),
            'vehicle_id' => $trip->vehicleId(),
            'driver_id' => $trip->driverId(),
            'route_id' => $trip->routeId(),
            'status' => $trip->status()->value,
            'started_at' => $trip->startedAt(),
            'ended_at' => $trip->endedAt(),
            'start_lat' => $trip->startPoint()->lat,
            'start_lng' => $trip->startPoint()->lng,
            'end_lat' => $end?->lat,
            'end_lng' => $end?->lng,
            'distance_m' => $trip->distanceM(),
            'duration_s' => $trip->durationS(),
            'moving_s' => $trip->movingS(),
            'idle_s' => $trip->idleS(),
            'max_speed_kph' => $trip->maxSpeedKph(),
            'ping_count' => $trip->pingCount(),
        ];
    }

    /**
     * Reinterpreta una fecha leída de la base (sesión PG en UTC) como el mismo reloj de pared en la zona de
     * la app: evita el desfase al compararla con instantes reales (Clock). Si alguna vez se alinea la zona de
     * la sesión de PG con la app, quitar este método y leer la fecha directo.
     */
    private static function wallInstant(DateTimeImmutable $value): DateTimeImmutable
    {
        return new DateTimeImmutable(
            $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
            new DateTimeZone(date_default_timezone_get()),
        );
    }
}
