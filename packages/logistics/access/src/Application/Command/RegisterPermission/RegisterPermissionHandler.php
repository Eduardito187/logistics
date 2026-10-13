<?php

declare(strict_types=1);

namespace Logistics\Access\Application\Command\RegisterPermission;

use Logistics\Access\Domain\Contract\PermissionRepository;
use Logistics\Access\Domain\Exception\AccessException;
use Logistics\Access\Domain\Model\Permission;
use Logistics\Access\Domain\ValueObject\PermissionKey;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Construye el permiso, lo persiste y publica sus eventos al outbox. Falla si la clave ya existe.
 *
 * El save del repositorio y el append al outbox corren dentro de la misma transacción, que abre el
 * decorador transaccional del CommandBus (Infrastructure); por eso acá no hay nada del framework.
 *
 * @implements CommandHandler<RegisterPermission>
 */
final readonly class RegisterPermissionHandler implements CommandHandler
{
    public function __construct(
        private PermissionRepository $permissions,
        private RecordDomainEvents $recordEvents,
    ) {}

    public function handle(Command $command): void
    {
        assert($command instanceof RegisterPermission);

        $key = PermissionKey::fromString($command->key);
        if ($this->permissions->existsByKey($key->value)) {
            throw AccessException::because("Ya existe el permiso {$key->value}.");
        }

        $permission = Permission::register(
            $this->permissions->nextIdentity(),
            $key,
            $command->description,
            $command->isSystem,
        );

        $this->permissions->save($permission);

        $this->recordEvents->record($permission->releaseEvents(), 'access.permission');
    }
}
