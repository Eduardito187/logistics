<?php

declare(strict_types=1);

use Logistics\Access\Domain\Exception\AccessException;
use Logistics\Access\Domain\ValueObject\RoleKey;

// VO RoleKey: slug estable (minúsculas, dígitos y guiones) con el que un usuario declara sus roles.

it('normaliza un slug válido a minúsculas', function (): void {
    $key = RoleKey::fromString('  Warehouse-Supervisor ');

    expect($key->value)->toBe('warehouse-supervisor');
});

it('rechaza slugs con formato inválido', function (string $value): void {
    expect(fn () => RoleKey::fromString($value))->toThrow(AccessException::class);
})->with(['', 'with space', 'bad_underscore', '-leading', 'trailing-', 'dot.ted']);

it('compara por su valor de texto', function (): void {
    expect(RoleKey::fromString('admin')->equals(RoleKey::fromString('ADMIN')))->toBeTrue()
        ->and(RoleKey::fromString('admin')->equals(RoleKey::fromString('picker')))->toBeFalse();
});
