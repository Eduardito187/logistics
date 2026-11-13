<?php

declare(strict_types=1);

use Logistics\Fleet\Domain\Exception\FleetException;
use Logistics\Fleet\Domain\ValueObject\PhoneNumber;

/**
 * Teléfono: acepta dígitos y separadores habituales, colapsando espacios internos.
 */
it('colapsa espacios internos y recorta los extremos', function (): void {
    $phone = PhoneNumber::of('  +591  700   12345  ');

    expect($phone->value)->toBe('+591 700 12345')
        ->and((string) $phone)->toBe('+591 700 12345');
});

it('rechaza teléfonos con formato inválido', function (string $value): void {
    expect(fn () => PhoneNumber::of($value))
        ->toThrow(FleetException::class, 'Número de teléfono inválido');
})->with([
    'muy corto' => ['123'],
    'con letras' => ['70012ABC'],
    'vacío' => [''],
]);

it('compara dos teléfonos por su valor normalizado', function (): void {
    expect(PhoneNumber::of('700 11122')->equals(PhoneNumber::of('700 11122')))->toBeTrue()
        ->and(PhoneNumber::of('70011122')->equals(PhoneNumber::of('70033344')))->toBeFalse();
});
