<?php

declare(strict_types=1);

use Logistics\Access\Domain\Exception\AccessException;
use Logistics\Access\Domain\ValueObject\PermissionKey;

// VO PermissionKey: clave "<grupo>.<accion>" en minúsculas, o el comodín "*".

it('normaliza una clave válida a minúsculas y deriva el grupo', function (): void {
    $key = PermissionKey::fromString('  Shipments.Read  ');

    expect($key->value)->toBe('shipments.read')
        ->and($key->group())->toBe('shipments')
        ->and($key->isWildcard())->toBeFalse();
});

it('acepta claves anidadas y usa el primer segmento como grupo', function (): void {
    $key = PermissionKey::fromString('access.roles.manage');

    expect($key->group())->toBe('access')
        ->and($key->value)->toBe('access.roles.manage');
});

it('reconoce el comodín y lo deja sin grupo concreto', function (): void {
    $key = PermissionKey::fromString('*');

    expect($key->isWildcard())->toBeTrue()
        ->and($key->group())->toBe('*');
});

it('rechaza claves con formato inválido', function (string $value): void {
    expect(fn () => PermissionKey::fromString($value))->toThrow(AccessException::class);
})->with(['shipments', 'ship ments.read', 'shipments..read', '', 'shipments:read']);

it('compara por su valor de texto', function (): void {
    expect(PermissionKey::fromString('fleet.read')->equals(PermissionKey::fromString('FLEET.READ')))->toBeTrue()
        ->and(PermissionKey::fromString('fleet.read')->equals(PermissionKey::fromString('fleet.write')))->toBeFalse();
});
