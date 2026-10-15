<?php

declare(strict_types=1);

namespace Logistics\Access\Infrastructure\Persistence\Eloquent\Repository;

use Logistics\Access\Domain\Contract\RoleRepository;
use Logistics\Access\Domain\Model\Role;
use Logistics\Access\Domain\ValueObject\RoleId;
use Logistics\Access\Infrastructure\Persistence\Eloquent\Mapper\RoleMapper;
use Logistics\Access\Infrastructure\Persistence\Eloquent\Model\RoleRecord;

/**
 * Implementación Eloquent del repositorio de roles. `save` es un upsert por id.
 */
final readonly class EloquentRoleRepository implements RoleRepository
{
    public function __construct(
        private RoleMapper $mapper = new RoleMapper,
    ) {}

    public function nextIdentity(): RoleId
    {
        return RoleId::generate();
    }

    public function save(Role $role): void
    {
        $row = $this->mapper->toRow($role);
        RoleRecord::query()->updateOrCreate(['id' => $row['id']], $row);
    }

    public function findById(RoleId $id): ?Role
    {
        $record = RoleRecord::query()->find($id->toString());

        return $record instanceof RoleRecord ? $this->mapper->toDomain($record) : null;
    }

    public function findByKey(string $key): ?Role
    {
        $record = RoleRecord::query()->where('key', $key)->first();

        return $record instanceof RoleRecord ? $this->mapper->toDomain($record) : null;
    }

    public function all(): array
    {
        return array_values(RoleRecord::query()
            ->orderBy('name')
            ->get()
            ->map(fn (RoleRecord $record): Role => $this->mapper->toDomain($record))
            ->all());
    }

    public function delete(RoleId $id): void
    {
        RoleRecord::query()->whereKey($id->toString())->delete();
    }
}
