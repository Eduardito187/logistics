<?php

declare(strict_types=1);

namespace Logistics\Access\Presentation\Filament\Resources\PermissionResource\Pages;

use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Logistics\Access\Application\Command\RegisterPermission\RegisterPermission;
use Logistics\Access\Infrastructure\Persistence\Eloquent\Model\PermissionRecord;
use Logistics\Access\Presentation\Filament\Resources\PermissionResource;
use Logistics\Access\Presentation\Filament\Support\AuditRecorder;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Alta de un permiso desde el admin.
 *
 * No persiste con Eloquent: despacha el comando RegisterPermission al bus CQRS (el dominio genera el id
 * y deriva grupo/acción de la clave). Como el comando no devuelve el id, se recupera el record recién
 * creado por su clave (única) para que Filament pueda redirigir a la vista del recurso.
 */
final class CreatePermission extends CreateRecord
{
    protected static string $resource = PermissionResource::class;

    /**
     * @param array<string, mixed> $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $key = (string) $data['key'];

        $description = isset($data['description']) && $data['description'] !== ''
            ? (string) $data['description']
            : null;

        app(CommandBus::class)->dispatch(new RegisterPermission(
            key: $key,
            description: $description,
            isSystem: (bool) ($data['is_system'] ?? false),
        ));

        // La clave es única: identifica el permiso recién registrado para la redirección del admin.
        $record = PermissionRecord::query()->where('key', $key)->firstOrFail();

        AuditRecorder::record('permission.registered', 'access.permission', (string) $record->getKey(), [
            'key' => $key,
            'is_system' => (bool) ($data['is_system'] ?? false),
        ]);

        return $record;
    }
}
