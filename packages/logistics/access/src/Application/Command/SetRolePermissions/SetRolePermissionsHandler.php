<?php

declare(strict_types=1);

namespace Logistics\Access\Application\Command\SetRolePermissions;

use Logistics\Access\Domain\Contract\RoleRepository;
use Logistics\Access\Domain\Exception\AccessException;
use Logistics\Access\Domain\ValueObject\RoleId;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Carga el rol, reemplaza su conjunto de permisos, lo persiste y publica sus eventos al outbox.
 *
 * El save del repositorio y el append al outbox corren dentro de la misma transacción, que abre el
 * decorador transaccional del CommandBus (Infrastructure); por eso acá no hay nada del framework.
 *
 * @implements CommandHandler<SetRolePermissions>
 */
final readonly class SetRolePermissionsHandler implements CommandHandler
{
    public function __construct(
        private RoleRepository $roles,
        private RecordDomainEvents $recordEvents,
    ) {}

    public function handle(Command $command): void
    {
        assert($command instanceof SetRolePermissions);

        $role = $this->roles->findById(RoleId::fromString($command->roleId));
        if ($role === null) {
            throw AccessException::because("No existe el rol {$command->roleId}.");
        }

        $role->setPermissions($command->permissionKeys);

        $this->roles->save($role);

        $this->recordEvents->record($role->releaseEvents(), 'access.role');
    }
}
