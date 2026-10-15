<?php

declare(strict_types=1);

namespace Logistics\Access\Application\Command\SuspendUser;

use Logistics\Access\Domain\Contract\UserRepository;
use Logistics\Access\Domain\Exception\AccessException;
use Logistics\Access\Domain\ValueObject\UserId;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Carga el usuario, lo suspende, lo persiste y publica sus eventos al outbox. Falla si no existe.
 *
 * @implements CommandHandler<SuspendUser>
 */
final readonly class SuspendUserHandler implements CommandHandler
{
    public function __construct(
        private UserRepository $users,
        private RecordDomainEvents $recordEvents,
    ) {}

    public function handle(Command $command): void
    {
        assert($command instanceof SuspendUser);

        $user = $this->users->findById(UserId::fromString($command->userId));
        if ($user === null) {
            throw AccessException::because("No existe el usuario {$command->userId}.");
        }

        $user->suspend();

        $this->users->save($user);

        $this->recordEvents->record($user->releaseEvents(), 'access.user');
    }
}
