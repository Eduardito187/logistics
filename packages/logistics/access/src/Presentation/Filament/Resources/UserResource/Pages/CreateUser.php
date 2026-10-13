<?php

declare(strict_types=1);

namespace Logistics\Access\Presentation\Filament\Resources\UserResource\Pages;

use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Logistics\Access\Application\Command\RegisterUser\RegisterUser;
use Logistics\Access\Infrastructure\Persistence\Eloquent\Model\UserRecord;
use Logistics\Access\Presentation\Filament\Resources\UserResource;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Alta de un usuario desde el admin.
 *
 * No persiste con Eloquent: despacha el comando RegisterUser al bus CQRS (el dominio genera el id y
 * hashea la contraseña). Como el comando no devuelve el id, se recupera el record recién creado por su
 * email (único) para la redirección. La contraseña en claro nunca se guarda ni se vuelve a mostrar.
 */
final class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    /**
     * @param array<string, mixed> $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $email = (string) $data['email'];

        /** @var list<string> $roleKeys */
        $roleKeys = array_values(is_array($data['role_keys'] ?? null) ? $data['role_keys'] : []);

        app(CommandBus::class)->dispatch(new RegisterUser(
            name: (string) $data['name'],
            email: $email,
            plainPassword: (string) $data['password'],
            roleKeys: $roleKeys,
            status: (string) ($data['status'] ?? 'active'),
        ));

        // El email es único: identifica el usuario recién registrado para la redirección del admin.
        return UserRecord::query()->where('email', $email)->firstOrFail();
    }
}
