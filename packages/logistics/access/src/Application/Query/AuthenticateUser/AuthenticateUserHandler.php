<?php

declare(strict_types=1);

namespace Logistics\Access\Application\Query\AuthenticateUser;

use Logistics\Access\Application\ReadModel\AuthenticatedUserView;
use Logistics\Access\Domain\Contract\PasswordHasher;
use Logistics\Access\Domain\Contract\UserRepository;
use Logistics\Access\Domain\Exception\AccessException;
use Logistics\Access\Domain\ValueObject\EmailAddress;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;

/**
 * Verifica las credenciales: busca el usuario por correo y devuelve su proyección autenticada, o null
 * si el correo no existe, el usuario no está activo o la contraseña no coincide. No registra el login.
 *
 * @implements QueryHandler<AuthenticateUser, AuthenticatedUserView|null>
 */
final readonly class AuthenticateUserHandler implements QueryHandler
{
    public function __construct(
        private UserRepository $users,
        private PasswordHasher $passwordHasher,
    ) {}

    public function handle(Query $query): ?AuthenticatedUserView
    {
        assert($query instanceof AuthenticateUser);

        try {
            $email = EmailAddress::fromString($query->email);
        } catch (AccessException) {
            // Un correo mal formado es, simplemente, un intento de autenticación fallido.
            return null;
        }

        $user = $this->users->findByEmail($email->value);
        if ($user === null || !$user->isActive()) {
            return null;
        }

        if (!$user->verifyPassword($query->plainPassword, $this->passwordHasher)) {
            return null;
        }

        return AuthenticatedUserView::fromDomain($user);
    }
}
