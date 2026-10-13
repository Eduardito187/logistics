<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Infrastructure\Persistence\Eloquent\Mapper;

use DateTimeImmutable;
use Logistics\Telemetry\Domain\Model\VehiclePing;
use Logistics\Telemetry\Domain\ValueObject\GeoPoint;
use Logistics\Telemetry\Domain\ValueObject\Heading;
use Logistics\Telemetry\Domain\ValueObject\PingId;
use Logistics\Telemetry\Domain\ValueObject\PingSource;
use Logistics\Telemetry\Domain\ValueObject\Speed;
use Logistics\Telemetry\Domain\ValueObject\TripId;
use Logistics\Telemetry\Infrastructure\Persistence\Eloquent\Model\PingRecord;

/**
 * Traduce entre la entidad VehiclePing (dominio) y su fila Eloquent. Único punto que conoce ambos.
 */
final class PingMapper
{
    public function toDomain(PingRecord $record): VehiclePing
    {
        return VehiclePing::reconstitute(
            PingId::fromString($record->id),
            $record->vehicle_id,
            $record->driver_id,
            $record->device_id,
            $record->route_id,
            GeoPoint::of($record->lat, $record->lng),
            $record->speed_kph === null ? null : Speed::kmh($record->speed_kph),
            $record->heading === null ? null : Heading::degrees($record->heading),
            $record->accuracy_m,
            $record->ignition,
            $record->odometer_m,
            $record->recorded_at->toDateTimeImmutable(),
            $record->received_at->toDateTimeImmutable(),
            PingSource::from($record->source),
            $record->trip_id === null ? null : TripId::fromString($record->trip_id),
        );
    }

    /**
     * @return array{id: string, vehicle_id: string, driver_id: string|null, device_id: string|null, route_id: string|null, lat: float, lng: float, speed_kph: float|null, heading: int|null, accuracy_m: float|null, ignition: bool|null, odometer_m: int|null, recorded_at: DateTimeImmutable, received_at: DateTimeImmutable, source: string, trip_id: string|null}
     */
    public function toRow(VehiclePing $ping): array
    {
        return [
            'id' => $ping->id()->toString(),
            'vehicle_id' => $ping->vehicleId(),
            'driver_id' => $ping->driverId(),
            'device_id' => $ping->deviceId(),
            'route_id' => $ping->routeId(),
            'lat' => $ping->point()->lat,
            'lng' => $ping->point()->lng,
            'speed_kph' => $ping->speed()?->kph,
            'heading' => $ping->heading()?->degrees,
            'accuracy_m' => $ping->accuracyM(),
            'ignition' => $ping->ignition(),
            'odometer_m' => $ping->odometerM(),
            'recorded_at' => $ping->recordedAt(),
            'received_at' => $ping->receivedAt(),
            'source' => $ping->source()->value,
            'trip_id' => $ping->tripId()?->toString(),
        ];
    }
}
