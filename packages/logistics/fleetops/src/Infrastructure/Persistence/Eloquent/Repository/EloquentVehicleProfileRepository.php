<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Infrastructure\Persistence\Eloquent\Repository;

use Logistics\FleetOps\Domain\Contract\VehicleProfileRepository;
use Logistics\FleetOps\Domain\Model\VehicleProfile;
use Logistics\FleetOps\Infrastructure\Persistence\Eloquent\Mapper\VehicleProfileMapper;
use Logistics\FleetOps\Infrastructure\Persistence\Eloquent\Model\VehicleProfileRecord;

/**
 * Implementación Eloquent del repositorio de perfiles de vehículos. `save` es un upsert por vehicle_id
 * (la PK de la tabla), lo que materializa la relación 1:1 con el vehículo.
 */
final readonly class EloquentVehicleProfileRepository implements VehicleProfileRepository
{
    public function __construct(
        private VehicleProfileMapper $mapper = new VehicleProfileMapper,
    ) {}

    public function save(VehicleProfile $profile): void
    {
        $row = $this->mapper->toRow($profile);
        VehicleProfileRecord::query()->updateOrCreate(['vehicle_id' => $row['vehicle_id']], $row);
    }

    public function findByVehicle(string $vehicleId): ?VehicleProfile
    {
        $record = VehicleProfileRecord::query()->find($vehicleId);

        return $record instanceof VehicleProfileRecord ? $this->mapper->toDomain($record) : null;
    }

    /**
     * @return list<VehicleProfile>
     */
    public function all(): array
    {
        return VehicleProfileRecord::query()
            ->orderBy('vehicle_id')
            ->get()
            ->map(fn (VehicleProfileRecord $record): VehicleProfile => $this->mapper->toDomain($record))
            ->values()
            ->all();
    }
}
