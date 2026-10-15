<?php

declare(strict_types=1);

namespace Logistics\Access\Application\Command\ChangeUserPassword;

use Logistics\Access\Domain\Contract\PasswordHasher;
use Logistics\Access\Domain\Contract\UserRepository;
use Logistics\Access\Domain\Exception\AccessException;
use Logistics\Access\Domain\ValueObject\UserId;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Carga el usuario, hashea la contraseña nueva vía el puerto, la cambia, persiste y publica al outbox.
 * Falla si no existe.
 *
 * @implements CommandHandler<ChangeUserPassword>
 */
final readonly class ChangeUserPasswordHandler implements CommandHandler
{
    public function __construct(
        private UserRepository $users,
        private PasswordHasher $passwordHasher,
        private RecordDomainEvents $recordEvents,
    ) {}

    public function handle(Command $command): void
    {
        assert($command instanceof ChangeUserPassword);

        $user = $this->users->findById(UserId::fromString($command->userId));
        if ($user === null) {
            throw AccessException::because("No existe el usuario {$command->userId}.");
        }

        $user->changePassword($this->passwordHasher->hash($command->newPlainPassword));

        $this->users->save($user);

        $this->recordEvents->record($user->releaseEvents(), 'access.user');
    }
}
