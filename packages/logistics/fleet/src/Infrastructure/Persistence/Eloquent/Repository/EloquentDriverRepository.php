<?php

declare(strict_types=1);

namespace Logistics\Fleet\Infrastructure\Persistence\Eloquent\Repository;

use Logistics\Fleet\Domain\Contract\DriverRepository;
use Logistics\Fleet\Domain\Model\Driver;
use Logistics\Fleet\Domain\ValueObject\DriverId;
use Logistics\Fleet\Infrastructure\Persistence\Eloquent\Mapper\DriverMapper;
use Logistics\Fleet\Infrastructure\Persistence\Eloquent\Model\DriverModel;

/**
 * Implementación Eloquent del repositorio de choferes. `save` es un upsert por id.
 */
final readonly class EloquentDriverRepository implements DriverRepository
{
    public function __construct(
        private DriverMapper $mapper = new DriverMapper,
    ) {}

    public function nextIdentity(): DriverId
    {
        return DriverId::generate();
    }

    public function save(Driver $driver): void
    {
        $row = $this->mapper->toRow($driver);
        DriverModel::query()->updateOrCreate(['id' => $row['id']], $row);
    }

    public function findById(DriverId $id): ?Driver
    {
        $model = DriverModel::query()->find($id->toString());

        return $model instanceof DriverModel ? $this->mapper->toDomain($model) : null;
    }

    public function all(): array
    {
        return array_values(DriverModel::query()
            ->orderBy('name')
            ->get()
            ->map(fn (DriverModel $model): Driver => $this->mapper->toDomain($model))
            ->all());
    }
}
