<?php

declare(strict_types=1);

namespace Logistics\Access\Presentation\Filament\Resources\RoleResource\Pages;

use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Logistics\Access\Application\Command\DefineRole\DefineRole;
use Logistics\Access\Infrastructure\Persistence\Eloquent\Model\RoleRecord;
use Logistics\Access\Presentation\Filament\Resources\RoleResource;
use Logistics\Access\Presentation\Filament\Support\AuditRecorder;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Alta de un rol desde el admin.
 *
 * No persiste con Eloquent: despacha el comando DefineRole al bus CQRS (el dominio genera el id). Como el
 * comando no devuelve el id, se recupera el record recién creado por su clave (única) para la redirección.
 */
final class CreateRole extends CreateRecord
{
    protected static string $resource = RoleResource::class;

    /**
     * @param array<string, mixed> $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $key = (string) $data['key'];

        $description = isset($data['description']) && $data['description'] !== ''
            ? (string) $data['description']
            : null;

        /** @var list<string> $permissionKeys */
        $permissionKeys = array_values(is_array($data['permission_keys'] ?? null) ? $data['permission_keys'] : []);

        app(CommandBus::class)->dispatch(new DefineRole(
            key: $key,
            name: (string) $data['name'],
            description: $description,
            permissionKeys: $permissionKeys,
        ));

        // La clave es única: identifica el rol recién definido para la redirección del admin.
        $record = RoleRecord::query()->where('key', $key)->firstOrFail();

        AuditRecorder::record('role.defined', 'access.role', (string) $record->getKey(), [
            'key' => $key,
            'permissions' => $permissionKeys,
        ]);

        return $record;
    }
}
