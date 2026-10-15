<?php

declare(strict_types=1);

namespace Logistics\Access\Infrastructure\Persistence\Eloquent\Repository;

use Logistics\Access\Domain\Contract\PermissionRepository;
use Logistics\Access\Domain\Model\Permission;
use Logistics\Access\Domain\ValueObject\PermissionId;
use Logistics\Access\Infrastructure\Persistence\Eloquent\Mapper\PermissionMapper;
use Logistics\Access\Infrastructure\Persistence\Eloquent\Model\PermissionRecord;

/**
 * Implementación Eloquent del repositorio de permisos. `save` es un upsert por id.
 */
final readonly class EloquentPermissionRepository implements PermissionRepository
{
    public function __construct(
        private PermissionMapper $mapper = new PermissionMapper,
    ) {}

    public function nextIdentity(): PermissionId
    {
        return PermissionId::generate();
    }

    public function save(Permission $permission): void
    {
        $row = $this->mapper->toRow($permission);
        PermissionRecord::query()->updateOrCreate(['id' => $row['id']], $row);
    }

    public function findByKey(string $key): ?Permission
    {
        $record = PermissionRecord::query()->where('key', $key)->first();

        return $record instanceof PermissionRecord ? $this->mapper->toDomain($record) : null;
    }

    public function findById(PermissionId $id): ?Permission
    {
        $record = PermissionRecord::query()->find($id->toString());

        return $record instanceof PermissionRecord ? $this->mapper->toDomain($record) : null;
    }

    public function all(): array
    {
        return array_values(PermissionRecord::query()
            ->orderBy('key')
            ->get()
            ->map(fn (PermissionRecord $record): Permission => $this->mapper->toDomain($record))
            ->all());
    }

    public function existsByKey(string $key): bool
    {
        return PermissionRecord::query()->where('key', $key)->exists();
    }
}
