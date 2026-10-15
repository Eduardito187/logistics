<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Infrastructure\Persistence\Eloquent\Repository;

use Logistics\Telemetry\Domain\Contract\GeofenceRepository;
use Logistics\Telemetry\Domain\Model\Geofence;
use Logistics\Telemetry\Domain\ValueObject\GeofenceId;
use Logistics\Telemetry\Infrastructure\Persistence\Eloquent\Mapper\GeofenceMapper;
use Logistics\Telemetry\Infrastructure\Persistence\Eloquent\Model\GeofenceRecord;

/**
 * Implementación Eloquent del repositorio de geocercas. `save` es un upsert por id.
 */
final readonly class EloquentGeofenceRepository implements GeofenceRepository
{
    public function __construct(
        private GeofenceMapper $mapper = new GeofenceMapper,
    ) {}

    public function nextIdentity(): GeofenceId
    {
        return GeofenceId::generate();
    }

    public function save(Geofence $geofence): void
    {
        $row = $this->mapper->toRow($geofence);
        GeofenceRecord::query()->updateOrCreate(['id' => $row['id']], $row);
    }

    public function findById(GeofenceId $id): ?Geofence
    {
        $record = GeofenceRecord::query()->find($id->toString());

        return $record instanceof GeofenceRecord ? $this->mapper->toDomain($record) : null;
    }

    public function all(): array
    {
        return array_values(GeofenceRecord::query()
            ->orderBy('name')
            ->get()
            ->map(fn (GeofenceRecord $record): Geofence => $this->mapper->toDomain($record))
            ->all());
    }

    public function activeAll(): array
    {
        return array_values(GeofenceRecord::query()
            ->where('active', true)
            ->orderBy('name')
            ->get()
            ->map(fn (GeofenceRecord $record): Geofence => $this->mapper->toDomain($record))
            ->all());
    }

    public function delete(GeofenceId $id): void
    {
        GeofenceRecord::query()->whereKey($id->toString())->delete();
    }
}
