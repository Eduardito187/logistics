<?php

declare(strict_types=1);

use Logistics\Access\Application\Service\PermissionResolver;
use Logistics\Access\Domain\Contract\RoleRepository;
use Logistics\Access\Domain\Model\Role;
use Logistics\Access\Domain\Model\User;
use Logistics\Access\Domain\ValueObject\EmailAddress;
use Logistics\Access\Domain\ValueObject\RoleId;
use Logistics\Access\Domain\ValueObject\RoleKey;
use Logistics\Access\Domain\ValueObject\UserId;

/**
 * Doble en memoria de RoleRepository para probar la resolución de permisos sin tocar la base.
 *
 * @param list<Role> $roles
 */
function rolesEnMemoria(array $roles): RoleRepository
{
    return new class($roles) implements RoleRepository
    {
        /** @var array<string, Role> roles indexados por su clave */
        private array $byKey = [];

        /**
         * @param list<Role> $roles
         */
        public function __construct(array $roles)
        {
            foreach ($roles as $role) {
                $this->byKey[$role->key()->value] = $role;
            }
        }

        public function nextIdentity(): RoleId
        {
            return RoleId::generate();
        }

        public function save(Role $role): void
        {
            $this->byKey[$role->key()->value] = $role;
        }

        public function findById(RoleId $id): ?Role
        {
            foreach ($this->byKey as $role) {
                if ($role->id()->equals($id)) {
                    return $role;
                }
            }

            return null;
        }

        public function findByKey(string $key): ?Role
        {
            return $this->byKey[$key] ?? null;
        }

        public function all(): array
        {
            return array_values($this->byKey);
        }

        public function delete(RoleId $id): void
        {
            foreach ($this->byKey as $key => $role) {
                if ($role->id()->equals($id)) {
                    unset($this->byKey[$key]);
                }
            }
        }
    };
}

/**
 * @param list<string> $permissionKeys
 */
function rolConPermisos(string $key, array $permissionKeys): Role
{
    return Role::define(RoleId::generate(), RoleKey::fromString($key), ucfirst($key), null, $permissionKeys);
}

it('une los permisos de todos los roles del conjunto', function (): void {
    $repo = rolesEnMemoria([
        rolConPermisos('picker', ['shipments.read', 'execution.write']),
        rolConPermisos('driver', ['shipments.read', 'tracking.write']),
    ]);
    $resolver = new PermissionResolver($repo);

    $granted = $resolver->forRoleKeys(['picker', 'driver']);

    expect($granted)->toEqualCanonicalizing(['shipments.read', 'execution.write', 'tracking.write']);
});

it('cortocircuita con el comodín si algún rol lo concede', function (): void {
    $repo = rolesEnMemoria([
        rolConPermisos('picker', ['shipments.read']),
        rolConPermisos('admin', ['*']),
    ]);
    $resolver = new PermissionResolver($repo);

    expect($resolver->forRoleKeys(['picker', 'admin']))->toBe(['*']);
});

it('ignora los roles inexistentes sin romper la resolución', function (): void {
    $repo = rolesEnMemoria([rolConPermisos('picker', ['shipments.read'])]);
    $resolver = new PermissionResolver($repo);

    expect($resolver->forRoleKeys(['picker', 'rol-borrado']))->toBe(['shipments.read']);
});

it('resuelve los permisos efectivos a partir de un usuario', function (): void {
    $repo = rolesEnMemoria([rolConPermisos('picker', ['shipments.read', 'execution.write'])]);
    $resolver = new PermissionResolver($repo);

    $user = User::register(UserId::generate(), 'Ada', EmailAddress::fromString('ada@dismac.com'), 'h', ['picker']);

    expect($resolver->forUser($user))->toEqualCanonicalizing(['shipments.read', 'execution.write']);
});

it('evalúa grants respetando el comodín', function (): void {
    expect(PermissionResolver::grants(['shipments.read'], 'shipments.read'))->toBeTrue()
        ->and(PermissionResolver::grants(['shipments.read'], 'shipments.write'))->toBeFalse()
        ->and(PermissionResolver::grants(['*'], 'anything.write'))->toBeTrue();
});
