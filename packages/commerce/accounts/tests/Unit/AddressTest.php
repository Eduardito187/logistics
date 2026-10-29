<?php

declare(strict_types=1);

use Commerce\Accounts\Domain\Exception\AccountsException;
use Commerce\Accounts\Domain\ValueObject\Address;

it('Address hace round-trip por array y normaliza opcionales vacíos a null', function (): void {
    $address = Address::of('Casa', 'Juan Pérez', 'Av. Siempre Viva 123', '  ', 'La Paz', null, '  ', true);

    expect($address->toArray())->toBe([
        'label' => 'Casa',
        'recipient' => 'Juan Pérez',
        'line1' => 'Av. Siempre Viva 123',
        'line2' => null,
        'city' => 'La Paz',
        'state' => null,
        'phone' => null,
        'is_default' => true,
    ]);

    $again = Address::fromArray($address->toArray());
    expect($again->label)->toBe('Casa')
        ->and($again->city)->toBe('La Paz')
        ->and($again->isDefault)->toBeTrue();
});

it('Address exige etiqueta, destinatario, línea 1 y ciudad', function (): void {
    expect(fn () => Address::of('  ', 'Juan', 'Calle 1', null, 'La Paz', null, null))
        ->toThrow(AccountsException::class);
    expect(fn () => Address::of('Casa', '  ', 'Calle 1', null, 'La Paz', null, null))
        ->toThrow(AccountsException::class);
    expect(fn () => Address::of('Casa', 'Juan', '  ', null, 'La Paz', null, null))
        ->toThrow(AccountsException::class);
    expect(fn () => Address::of('Casa', 'Juan', 'Calle 1', null, '  ', null, null))
        ->toThrow(AccountsException::class);
});

it('asNonDefault devuelve una copia no predeterminada', function (): void {
    $address = Address::of('Oficina', 'Ana', 'Calle 2', null, 'Santa Cruz', null, null, true);

    expect($address->isDefault)->toBeTrue()
        ->and($address->asNonDefault()->isDefault)->toBeFalse()
        ->and($address->asNonDefault()->label)->toBe('Oficina');
});
