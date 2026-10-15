<?php

declare(strict_types=1);

namespace Logistics\Access\Application\Command\DeleteRole;

use Logistics\Access\Domain\Contract\RoleRepository;
use Logistics\Access\Domain\Exception\AccessException;
use Logistics\Access\Domain\ValueObject\RoleId;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Carga el rol, verifica que se pueda borrar, lo elimina y publica sus eventos al outbox.
 *
 * Los roles de sistema no se eliminan (ensureDeletable lanza AccessException). El delete del repositorio
 * y el append al outbox corren dentro de la misma transacción del decorador transaccional del CommandBus.
 *
 * @implements CommandHandler<DeleteRole>
 */
final readonly class DeleteRoleHandler implements CommandHandler
{
    public function __construct(
        private RoleRepository $roles,
        private RecordDomainEvents $recordEvents,
    ) {}

    public function handle(Command $command): void
    {
        assert($command instanceof DeleteRole);

        $role = $this->roles->findById(RoleId::fromString($command->roleId));
        if ($role === null) {
            throw AccessException::because("No existe el rol {$command->roleId}.");
        }

        $role->ensureDeletable();
        $role->markDeleted();

        $this->roles->delete($role->id());

        $this->recordEvents->record($role->releaseEvents(), 'access.role');
    }
}
