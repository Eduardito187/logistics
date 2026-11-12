<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Infrastructure\Persistence\Eloquent\Repository;

use DateTimeImmutable;
use DateTimeZone;
use Logistics\Telemetry\Domain\Contract\VehiclePositionRepository;
use Logistics\Telemetry\Domain\Model\VehiclePing;
use Logistics\Telemetry\Infrastructure\Persistence\Eloquent\Mapper\VehiclePositionMapper;
use Logistics\Telemetry\Infrastructure\Persistence\Eloquent\Model\VehiclePositionRecord;

/**
 * Implementación Eloquent de la posición en vivo. `upsertFromPing` pisa la única fila del vehículo.
 *
 * El estado de condiciones sostenidas (speeding_since / off_route_since) vive en la misma fila pero se
 * escribe con un UPDATE directo que NO toca `updated_at`: así el polling incremental `?since=` solo ve
 * cambios de posición, no cambios de estado interno.
 */
final readonly class EloquentVehiclePositionRepository implements VehiclePositionRepository
{
    public function __construct(
        private VehiclePositionMapper $mapper = new VehiclePositionMapper,
    ) {}

    public function upsertFromPing(VehiclePing $ping, bool $moving): void
    {
        $row = $this->mapper->toRow($ping, $moving);
        VehiclePositionRecord::query()->updateOrCreate(['vehicle_id' => $row['vehicle_id']], $row);
    }

    public function all(): array
    {
        return array_values(VehiclePositionRecord::query()
            ->orderBy('vehicle_id')
            ->get()
            ->map(fn (VehiclePositionRecord $record): array => $this->mapper->toLiveRow($record))
            ->all());
    }

    public function forVehicle(string $vehicleId): ?array
    {
        $record = VehiclePositionRecord::query()->find($vehicleId);

        return $record instanceof VehiclePositionRecord ? $this->mapper->toLiveRow($record) : null;
    }

    public function changedSince(DateTimeImmutable $since): array
    {
        return array_values(VehiclePositionRecord::query()
            // >= (no >): updated_at tiene precisión de segundo; el polling es al-menos-una-vez (el cliente
            // pisa por vehicle_id), así no se pierde un cambio ocurrido en el mismo segundo del cursor.
            // updated_at es timestamp sin zona en la zona de la app: el cursor se lleva a esa zona antes de
            // bindear (el binding de fechas no convierte zona).
            ->where('updated_at', '>=', $since->setTimezone(new DateTimeZone(date_default_timezone_get())))
            ->orderBy('vehicle_id')
            ->get()
            ->map(fn (VehiclePositionRecord $record): array => $this->mapper->toLiveRow($record))
            ->all());
    }

    public function watchState(string $vehicleId): array
    {
        $record = VehiclePositionRecord::query()->find($vehicleId, ['vehicle_id', 'speeding_since', 'off_route_since']);

        if (!$record instanceof VehiclePositionRecord) {
            return ['speeding_since' => null, 'off_route_since' => null];
        }

        return [
            'speeding_since' => $record->speeding_since?->toDateTimeImmutable(),
            'off_route_since' => $record->off_route_since?->toDateTimeImmutable(),
        ];
    }

    public function saveWatchState(string $vehicleId, ?DateTimeImmutable $speedingSince, ?DateTimeImmutable $offRouteSince): void
    {
        // toBase(): UPDATE plano, sin tocar updated_at (no es un cambio de posición).
        VehiclePositionRecord::query()
            ->whereKey($vehicleId)
            ->toBase()
            ->update([
                'speeding_since' => $speedingSince,
                'off_route_since' => $offRouteSince,
            ]);
    }
}
