<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Infrastructure\Persistence\Eloquent\Mapper;

use DateTimeImmutable;
use Logistics\Telemetry\Domain\Model\VehiclePing;
use Logistics\Telemetry\Infrastructure\Persistence\Eloquent\Model\VehiclePositionRecord;

/**
 * Traduce la posición en vivo: del ping entrante a la fila que se pisa, y de la fila a la estructura
 * primitiva que consume la capa de lectura (el contrato de dominio no conoce los ReadModels).
 */
final class VehiclePositionMapper
{
    /**
     * Fila a persistir a partir del último ping del vehículo. `moving` lo decide el llamador.
     *
     * `driver_id` solo se pisa si la lectura lo trae (una lectura sin chofer no borra el último conocido).
     *
     * @return array{vehicle_id: string, lat: float, lng: float, speed_kph: float|null, heading: int|null, ignition: bool|null, moving: bool, route_id: string|null, recorded_at: DateTimeImmutable, driver_id?: string}
     */
    public function toRow(VehiclePing $ping, bool $moving): array
    {
        $row = [
            'vehicle_id' => $ping->vehicleId(),
            'lat' => $ping->point()->lat,
            'lng' => $ping->point()->lng,
            'speed_kph' => $ping->speed()?->kph,
            'heading' => $ping->heading()?->degrees,
            'ignition' => $ping->ignition(),
            'moving' => $moving,
            'route_id' => $ping->routeId(),
            'recorded_at' => $ping->recordedAt(),
        ];

        $driverId = $ping->driverId();
        if ($driverId !== null && $driverId !== '') {
            $row['driver_id'] = $driverId;
        }

        return $row;
    }

    /**
     * Estructura primitiva de lectura a partir de la fila persistida.
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
            'recorded_at' => $record->recorded_at->toDateTimeImmutable(),
            'driver_id' => $record->driver_id,
            'updated_at' => $record->updated_at?->toDateTimeImmutable(),
        ];
    }
}
