<?php

declare(strict_types=1);

use Logistics\Access\Domain\Contract\PasswordHasher;
use Logistics\Access\Domain\Event\UserPasswordChanged;
use Logistics\Access\Domain\Event\UserReactivated;
use Logistics\Access\Domain\Event\UserRegistered;
use Logistics\Access\Domain\Event\UserRoleAssigned;
use Logistics\Access\Domain\Event\UserSuspended;
use Logistics\Access\Domain\Exception\AccessException;
use Logistics\Access\Domain\Model\User;
use Logistics\Access\Domain\ValueObject\EmailAddress;
use Logistics\Access\Domain\ValueObject\RoleKey;
use Logistics\Access\Domain\ValueObject\UserId;
use Logistics\Access\Domain\ValueObject\UserStatus;

/**
 * Doble de PasswordHasher para pruebas: hash determinista "hashed:<plain>" sin costo de bcrypt.
 */
function hasherDoble(): PasswordHasher
{
    return new class implements PasswordHasher
    {
        public function hash(string $plain): string
        {
            return 'hashed:'.$plain;
        }

        public function verify(string $plain, string $hash): bool
        {
            return $hash === 'hashed:'.$plain;
        }
    };
}

/**
 * Registra un usuario de dominio con una contraseña ya hasheada.
 *
 * @param list<string> $roleKeys
 */
function registrarUsuario(array $roleKeys = []): User
{
    return User::register(
        UserId::generate(),
        'Ada Lovelace',
        EmailAddress::fromString('ada@dismac.com'),
        'hashed:password123',
        $roleKeys,
    );
}

it('registra un usuario activo normalizando sus roles', function (): void {
    $user = registrarUsuario(['admin', 'ADMIN', 'picker']);

    expect($user->status())->toBe(UserStatus::Active)
        ->and($user->isActive())->toBeTrue()
        ->and($user->roleKeys())->toBe(['admin', 'picker'])
        ->and($user->email()->value)->toBe('ada@dismac.com');

    $events = $user->releaseEvents();
    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(UserRegistered::class)
        ->and($events[0]->eventName())->toBe('access.user_registered');
});

it('rechaza registrar un usuario sin nombre', function (): void {
    expect(fn () => User::register(UserId::generate(), '  ', EmailAddress::fromString('x@dismac.com'), 'h'))
        ->toThrow(AccessException::class);
});

it('suspende y reactiva de forma idempotente', function (): void {
    $user = registrarUsuario();
    $user->releaseEvents();

    $user->suspend();
    expect($user->status())->toBe(UserStatus::Suspended)
        ->and($user->isActive())->toBeFalse();
    $user->suspend(); // idempotente

    $user->reactivate();
    expect($user->status())->toBe(UserStatus::Active);
    $user->reactivate(); // idempotente

    $events = $user->releaseEvents();
    expect($events)->toHaveCount(2)
        ->and($events[0])->toBeInstanceOf(UserSuspended::class)
        ->and($events[1])->toBeInstanceOf(UserReactivated::class);
});

it('asigna y quita roles de forma idempotente', function (): void {
    $user = registrarUsuario();
    $user->releaseEvents();

    $user->assignRole(RoleKey::fromString('picker'));
    $user->assignRole(RoleKey::fromString('picker')); // idempotente
    expect($user->roleKeys())->toBe(['picker']);

    $user->removeRole(RoleKey::fromString('picker'));
    $user->removeRole(RoleKey::fromString('picker')); // idempotente
    expect($user->roleKeys())->toBe([]);

    $events = $user->releaseEvents();
    expect($events)->toHaveCount(2)
        ->and($events[0])->toBeInstanceOf(UserRoleAssigned::class);
});

it('cambia la contraseña grabando el evento', function (): void {
    $user = registrarUsuario();
    $user->releaseEvents();

    $user->changePassword('hashed:nuevo');

    $events = $user->releaseEvents();
    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(UserPasswordChanged::class);
});

it('verifica la contraseña delegando en el puerto sin grabar evento', function (): void {
    $user = registrarUsuario();
    $user->releaseEvents();
    $hasher = hasherDoble();

    expect($user->verifyPassword('password123', $hasher))->toBeTrue()
        ->and($user->verifyPassword('incorrecta', $hasher))->toBeFalse()
        ->and($user->releaseEvents())->toBe([]);
});

it('registra el instante del último login', function (): void {
    $user = registrarUsuario();
    $user->releaseEvents();

    $at = new DateTimeImmutable('2026-10-13T08:30:00+00:00');
    $user->recordLogin($at);

    expect($user->lastLoginAt())->toEqual($at)
        ->and($user->releaseEvents())->toHaveCount(1);
});
