<?php

declare(strict_types=1);

namespace Logistics\Access\Infrastructure\Persistence\Eloquent\Mapper;

use Logistics\Access\Domain\Model\Role;
use Logistics\Access\Domain\ValueObject\RoleId;
use Logistics\Access\Domain\ValueObject\RoleKey;
use Logistics\Access\Infrastructure\Persistence\Eloquent\Model\RoleRecord;

/**
 * Traduce entre el agregado Role (dominio) y su fila Eloquent. Único punto que conoce ambos.
 */
final class RoleMapper
{
    public function toDomain(RoleRecord $record): Role
    {
        return Role::reconstitute(
            RoleId::fromString($record->id),
            RoleKey::fromString($record->key),
            $record->name,
            $record->description,
            $record->is_system,
            array_values($record->permission_keys),
        );
    }

    /**
     * @return array{id: string, key: string, name: string, description: string|null, is_system: bool, permission_keys: list<string>}
     */
    public function toRow(Role $role): array
    {
        return [
            'id' => $role->id()->toString(),
            'key' => $role->key()->value,
            'name' => $role->name(),
            'description' => $role->description(),
            'is_system' => $role->isSystem(),
            'permission_keys' => $role->permissionKeys(),
        ];
    }
}
