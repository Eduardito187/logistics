<?php

declare(strict_types=1);

use Logistics\Access\Domain\Event\RoleDefined;
use Logistics\Access\Domain\Event\RolePermissionsChanged;
use Logistics\Access\Domain\Exception\AccessException;
use Logistics\Access\Domain\Model\Role;
use Logistics\Access\Domain\ValueObject\PermissionKey;
use Logistics\Access\Domain\ValueObject\RoleId;
use Logistics\Access\Domain\ValueObject\RoleKey;

/**
 * Define un rol de dominio con claves de permiso arbitrarias.
 *
 * @param list<string> $permissionKeys
 */
function definirRol(array $permissionKeys = [], bool $isSystem = false): Role
{
    return Role::define(
        RoleId::generate(),
        RoleKey::fromString('picker'),
        'Picker',
        null,
        $permissionKeys,
        $isSystem,
    );
}

it('define un rol normalizando y deduplicando las claves de permiso', function (): void {
    $role = definirRol(['shipments.read', 'Shipments.Read', 'execution.write']);

    expect($role->permissionKeys())->toBe(['shipments.read', 'execution.write'])
        ->and($role->name())->toBe('Picker');

    $events = $role->releaseEvents();
    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(RoleDefined::class)
        ->and($events[0]->eventName())->toBe('access.role_defined');
});

it('rechaza definir un rol sin nombre', function (): void {
    expect(fn () => Role::define(RoleId::generate(), RoleKey::fromString('picker'), '  ', null, []))
        ->toThrow(AccessException::class);
});

it('otorga un permiso de forma idempotente', function (): void {
    $role = definirRol(['shipments.read']);
    $role->releaseEvents();

    $role->grant(PermissionKey::fromString('execution.write'));
    expect($role->permissionKeys())->toBe(['shipments.read', 'execution.write']);

    // Segundo grant del mismo permiso: no cambia nada ni graba evento.
    $role->grant(PermissionKey::fromString('execution.write'));

    $events = $role->releaseEvents();
    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(RolePermissionsChanged::class);
});

it('revoca un permiso de forma idempotente', function (): void {
    $role = definirRol(['shipments.read', 'execution.write']);
    $role->releaseEvents();

    $role->revoke(PermissionKey::fromString('execution.write'));
    expect($role->permissionKeys())->toBe(['shipments.read']);

    // Revocar algo que ya no está: idempotente.
    $role->revoke(PermissionKey::fromString('execution.write'));

    expect($role->releaseEvents())->toHaveCount(1);
});

it('reemplaza el conjunto completo con setPermissions e ignora un set idéntico', function (): void {
    $role = definirRol(['shipments.read']);
    $role->releaseEvents();

    $role->setPermissions(['fleet.read', 'fleet.write']);
    expect($role->permissionKeys())->toBe(['fleet.read', 'fleet.write']);

    // Mismo set (ya normalizado): idempotente.
    $role->setPermissions(['fleet.read', 'fleet.write']);

    expect($role->releaseEvents())->toHaveCount(1);
});

it('respeta el comodín en hasPermission', function (): void {
    $role = definirRol(['*']);

    expect($role->hasPermission(PermissionKey::fromString('anything.read')))->toBeTrue()
        ->and($role->hasPermission(PermissionKey::fromString('other.write')))->toBeTrue();
});

it('concede solo los permisos que tiene cuando no hay comodín', function (): void {
    $role = definirRol(['shipments.read']);

    expect($role->hasPermission(PermissionKey::fromString('shipments.read')))->toBeTrue()
        ->and($role->hasPermission(PermissionKey::fromString('shipments.write')))->toBeFalse();
});

it('impide eliminar un rol de sistema', function (): void {
    $sistema = definirRol([], true);
    expect(fn () => $sistema->ensureDeletable())->toThrow(AccessException::class);

    $normal = definirRol([], false);
    $normal->ensureDeletable();
    expect($normal->isSystem())->toBeFalse();
});
