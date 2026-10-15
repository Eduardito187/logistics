<?php

declare(strict_types=1);

namespace Logistics\Access\Application\Command\RenameRole;

use Logistics\Access\Domain\Contract\RoleRepository;
use Logistics\Access\Domain\Exception\AccessException;
use Logistics\Access\Domain\ValueObject\RoleId;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Carga el rol, lo renombra, lo persiste y publica sus eventos al outbox. Falla si no existe.
 *
 * El save del repositorio y el append al outbox corren dentro de la misma transacción, que abre el
 * decorador transaccional del CommandBus (Infrastructure); por eso acá no hay nada del framework.
 *
 * @implements CommandHandler<RenameRole>
 */
final readonly class RenameRoleHandler implements CommandHandler
{
    public function __construct(
        private RoleRepository $roles,
        private RecordDomainEvents $recordEvents,
    ) {}

    public function handle(Command $command): void
    {
        assert($command instanceof RenameRole);

        $role = $this->roles->findById(RoleId::fromString($command->roleId));
        if ($role === null) {
            throw AccessException::because("No existe el rol {$command->roleId}.");
        }

        $role->rename($command->name, $command->description);

        $this->roles->save($role);

        $this->recordEvents->record($role->releaseEvents(), 'access.role');
    }
}
