<?php

declare(strict_types=1);

namespace Logistics\Access\Application\Command\AssignRoleToUser;

use Logistics\Access\Domain\Contract\UserRepository;
use Logistics\Access\Domain\Exception\AccessException;
use Logistics\Access\Domain\ValueObject\RoleKey;
use Logistics\Access\Domain\ValueObject\UserId;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Carga el usuario, le asigna el rol, lo persiste y publica sus eventos al outbox. Falla si no existe.
 *
 * @implements CommandHandler<AssignRoleToUser>
 */
final readonly class AssignRoleToUserHandler implements CommandHandler
{
    public function __construct(
        private UserRepository $users,
        private RecordDomainEvents $recordEvents,
    ) {}

    public function handle(Command $command): void
    {
        assert($command instanceof AssignRoleToUser);

        $user = $this->users->findById(UserId::fromString($command->userId));
        if ($user === null) {
            throw AccessException::because("No existe el usuario {$command->userId}.");
        }

        $user->assignRole(RoleKey::fromString($command->roleKey));

        $this->users->save($user);

        $this->recordEvents->record($user->releaseEvents(), 'access.user');
    }
}
