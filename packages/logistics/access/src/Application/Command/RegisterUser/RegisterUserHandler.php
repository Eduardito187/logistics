<?php

declare(strict_types=1);

namespace Logistics\Access\Application\Command\RegisterUser;

use Logistics\Access\Domain\Contract\PasswordHasher;
use Logistics\Access\Domain\Contract\UserRepository;
use Logistics\Access\Domain\Exception\AccessException;
use Logistics\Access\Domain\Model\User;
use Logistics\Access\Domain\ValueObject\EmailAddress;
use Logistics\Access\Domain\ValueObject\UserStatus;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Valida unicidad de correo, hashea la contraseña vía el puerto, construye el usuario, lo persiste y
 * publica sus eventos al outbox.
 *
 * El save del repositorio y el append al outbox corren dentro de la misma transacción, que abre el
 * decorador transaccional del CommandBus (Infrastructure); por eso acá no hay nada del framework.
 *
 * @implements CommandHandler<RegisterUser>
 */
final readonly class RegisterUserHandler implements CommandHandler
{
    public function __construct(
        private UserRepository $users,
        private PasswordHasher $passwordHasher,
        private RecordDomainEvents $recordEvents,
    ) {}

    public function handle(Command $command): void
    {
        assert($command instanceof RegisterUser);

        $email = EmailAddress::fromString($command->email);
        if ($this->users->existsByEmail($email->value)) {
            throw AccessException::because("Ya existe un usuario con el correo {$email->value}.");
        }

        $user = User::register(
            $this->users->nextIdentity(),
            $command->name,
            $email,
            $this->passwordHasher->hash($command->plainPassword),
            $command->roleKeys,
            UserStatus::from($command->status),
        );

        $this->users->save($user);

        $this->recordEvents->record($user->releaseEvents(), 'access.user');
    }
}
