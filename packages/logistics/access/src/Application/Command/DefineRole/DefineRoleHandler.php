<?php

declare(strict_types=1);

namespace Logistics\Access\Application\Command\DefineRole;

use Logistics\Access\Domain\Contract\RoleRepository;
use Logistics\Access\Domain\Exception\AccessException;
use Logistics\Access\Domain\Model\Role;
use Logistics\Access\Domain\ValueObject\RoleKey;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Construye el rol, lo persiste y publica sus eventos al outbox. Falla si la clave ya existe.
 *
 * El save del repositorio y el append al outbox corren dentro de la misma transacción, que abre el
 * decorador transaccional del CommandBus (Infrastructure); por eso acá no hay nada del framework.
 *
 * @implements CommandHandler<DefineRole>
 */
final readonly class DefineRoleHandler implements CommandHandler
{
    public function __construct(
        private RoleRepository $roles,
        private RecordDomainEvents $recordEvents,
    ) {}

    public function handle(Command $command): void
    {
        assert($command instanceof DefineRole);

        $key = RoleKey::fromString($command->key);
        if ($this->roles->findByKey($key->value) !== null) {
            throw AccessException::because("Ya existe el rol {$key->value}.");
        }

        $role = Role::define(
            $this->roles->nextIdentity(),
            $key,
            $command->name,
            $command->description,
            $command->permissionKeys,
            $command->isSystem,
        );

        $this->roles->save($role);

        $this->recordEvents->record($role->releaseEvents(), 'access.role');
    }
}
