<?php

declare(strict_types=1);

namespace Logistics\Fleet\Infrastructure\Persistence\Eloquent\Repository;

use Logistics\Fleet\Domain\Contract\VehicleRepository;
use Logistics\Fleet\Domain\Model\Vehicle;
use Logistics\Fleet\Domain\ValueObject\VehicleId;
use Logistics\Fleet\Infrastructure\Persistence\Eloquent\Mapper\VehicleMapper;
use Logistics\Fleet\Infrastructure\Persistence\Eloquent\Model\VehicleModel;

/**
 * Implementación Eloquent del repositorio de vehículos. `save` es un upsert por id.
 */
final readonly class EloquentVehicleRepository implements VehicleRepository
{
    public function __construct(
        private VehicleMapper $mapper = new VehicleMapper,
    ) {}

    public function nextIdentity(): VehicleId
    {
        return VehicleId::generate();
    }

    public function save(Vehicle $vehicle): void
    {
        $row = $this->mapper->toRow($vehicle);
        VehicleModel::query()->updateOrCreate(['id' => $row['id']], $row);
    }

    public function findById(VehicleId $id): ?Vehicle
    {
        $model = VehicleModel::query()->find($id->toString());

        return $model instanceof VehicleModel ? $this->mapper->toDomain($model) : null;
    }

    public function all(): array
    {
        return array_values(VehicleModel::query()
            ->orderBy('plate')
            ->get()
            ->map(fn (VehicleModel $model): Vehicle => $this->mapper->toDomain($model))
            ->all());
    }
}
