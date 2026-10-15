<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Infrastructure\Persistence\Eloquent\Repository;

use Logistics\Telemetry\Domain\Contract\VehiclePositionRepository;
use Logistics\Telemetry\Domain\Model\VehiclePing;
use Logistics\Telemetry\Infrastructure\Persistence\Eloquent\Mapper\VehiclePositionMapper;
use Logistics\Telemetry\Infrastructure\Persistence\Eloquent\Model\VehiclePositionRecord;

/**
 * Implementación Eloquent de la posición en vivo. `upsertFromPing` pisa la única fila del vehículo.
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
}
