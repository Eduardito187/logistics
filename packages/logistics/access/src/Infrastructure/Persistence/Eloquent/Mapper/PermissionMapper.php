<?php

declare(strict_types=1);

namespace Logistics\Access\Infrastructure\Persistence\Eloquent\Mapper;

use Logistics\Access\Domain\Model\Permission;
use Logistics\Access\Domain\ValueObject\PermissionId;
use Logistics\Access\Domain\ValueObject\PermissionKey;
use Logistics\Access\Infrastructure\Persistence\Eloquent\Model\PermissionRecord;

/**
 * Traduce entre el agregado Permission (dominio) y su fila Eloquent. Único punto que conoce ambos.
 */
final class PermissionMapper
{
    public function toDomain(PermissionRecord $record): Permission
    {
        return Permission::reconstitute(
            PermissionId::fromString($record->id),
            PermissionKey::fromString($record->key),
            $record->group,
            $record->action,
            $record->description,
            $record->is_system,
        );
    }

    /**
     * @return array{id: string, key: string, group: string, action: string, description: string|null, is_system: bool}
     */
    public function toRow(Permission $permission): array
    {
        return [
            'id' => $permission->id()->toString(),
            'key' => $permission->key()->value,
            'group' => $permission->group(),
            'action' => $permission->action(),
            'description' => $permission->description(),
            'is_system' => $permission->isSystem(),
        ];
    }
}
