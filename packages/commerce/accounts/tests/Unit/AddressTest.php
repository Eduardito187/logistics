<?php

declare(strict_types=1);

use Commerce\Accounts\Domain\Exception\AccountsException;
use Commerce\Accounts\Domain\ValueObject\Address;

// Literales de dirección reutilizados; nombres únicos para evitar colisión global entre tests Pest.
const ADDRESS_CITY = 'La Paz';
const ADDRESS_STREET = 'Calle 1';

it('Address hace round-trip por array y normaliza opcionales vacíos a null', function (): void {
    $address = Address::of('Casa', 'Juan Pérez', 'Av. Siempre Viva 123', '  ', ADDRESS_CITY, null, '  ', true);

    expect($address->toArray())->toBe([
        'label' => 'Casa',
        'recipient' => 'Juan Pérez',
        'line1' => 'Av. Siempre Viva 123',
        'line2' => null,
        'city' => ADDRESS_CITY,
        'state' => null,
        'phone' => null,
        'is_default' => true,
    ]);

    $again = Address::fromArray($address->toArray());
    expect($again->label)->toBe('Casa')
        ->and($again->city)->toBe(ADDRESS_CITY)
        ->and($again->isDefault)->toBeTrue();
});

it('Address exige etiqueta, destinatario, línea 1 y ciudad', function (): void {
    expect(fn () => Address::of('  ', 'Juan', ADDRESS_STREET, null, ADDRESS_CITY, null, null))
        ->toThrow(AccountsException::class);
    expect(fn () => Address::of('Casa', '  ', ADDRESS_STREET, null, ADDRESS_CITY, null, null))
        ->toThrow(AccountsException::class);
    expect(fn () => Address::of('Casa', 'Juan', '  ', null, ADDRESS_CITY, null, null))
        ->toThrow(AccountsException::class);
    expect(fn () => Address::of('Casa', 'Juan', ADDRESS_STREET, null, '  ', null, null))
        ->toThrow(AccountsException::class);
});

it('asNonDefault devuelve una copia no predeterminada', function (): void {
    $address = Address::of('Oficina', 'Ana', 'Calle 2', null, 'Santa Cruz', null, null, true);

    expect($address->isDefault)->toBeTrue()
        ->and($address->asNonDefault()->isDefault)->toBeFalse()
        ->and($address->asNonDefault()->label)->toBe('Oficina');
});
