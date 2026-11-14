<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Infrastructure\Persistence\Eloquent\Mapper;

use DateTimeImmutable;
use DateTimeZone;
use Logistics\Telemetry\Domain\Model\VehiclePing;
use Logistics\Telemetry\Infrastructure\Persistence\Eloquent\Model\VehiclePositionRecord;

/**
 * Traduce la posición en vivo: del ping entrante a la fila que se pisa, y de la fila a la estructura
 * primitiva que consume la capa de lectura (el contrato de dominio no conoce los ReadModels).
 */
final class VehiclePositionMapper
{
    /**
     * Fila de la posición en vivo a partir del ping (el `moving` lo decide el handler con el umbral).
     *
     * @return array{vehicle_id: string, lat: float, lng: float, speed_kph: float|null, heading: int|null, ignition: bool|null, moving: bool, route_id: string|null, recorded_at: DateTimeImmutable, driver_id: string|null}
     */
    public function toRow(VehiclePing $ping, bool $moving): array
    {
        return [
            'vehicle_id' => $ping->vehicleId(),
            'lat' => $ping->point()->lat,
            'lng' => $ping->point()->lng,
            'speed_kph' => $ping->speed()?->kph,
            'heading' => $ping->heading()?->degrees,
            'ignition' => $ping->ignition(),
            'moving' => $moving,
            'route_id' => $ping->routeId(),
            'recorded_at' => $ping->recordedAt(),
            'driver_id' => $ping->driverId(),
        ];
    }

    /**
     * Estructura primitiva de la posición en vivo para la capa de lectura (sin exponer el modelo Eloquent).
     *
     * @return array{vehicle_id: string, lat: float, lng: float, speed_kph: float|null, heading: int|null, ignition: bool|null, moving: bool, route_id: string|null, recorded_at: DateTimeImmutable, driver_id: string|null, updated_at: DateTimeImmutable|null}
     */
    public function toLiveRow(VehiclePositionRecord $record): array
    {
        return [
            'vehicle_id' => $record->vehicle_id,
            'lat' => $record->lat,
            'lng' => $record->lng,
            'speed_kph' => $record->speed_kph,
            'heading' => $record->heading,
            'ignition' => $record->ignition,
            'moving' => $record->moving,
            'route_id' => $record->route_id,
            'recorded_at' => self::wallInstant($record->recorded_at->toDateTimeImmutable()),
            'driver_id' => $record->driver_id,
            'updated_at' => $record->updated_at?->toDateTimeImmutable(),
        ];
    }

    /**
     * Reinterpreta una fecha leída de la base como el mismo reloj de pared en la zona de la app.
     *
     * Eloquent escribe la fecha como hora de pared de la app SIN offset; PostgreSQL la interpreta en la zona
     * de SU sesión y la devuelve con el offset de esa zona. El reloj de pared que devuelve es entonces
     * siempre el que escribió la app, sea cual sea la zona de la sesión (UTC o la de la app): se toma ese
     * reloj tal cual (sin convertir a UTC) y se lo ancla a la zona de la app. Convertirlo a UTC primero solo
     * funcionaba con la sesión en UTC y corría la fecha en cada lectura si la sesión estaba en otra zona.
     */
    private static function wallInstant(DateTimeImmutable $value): DateTimeImmutable
    {
        return new DateTimeImmutable(
            $value->format('Y-m-d H:i:s'),
            new DateTimeZone(date_default_timezone_get()),
        );
    }
}
