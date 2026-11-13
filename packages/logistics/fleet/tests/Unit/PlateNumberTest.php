<?php

declare(strict_types=1);

use Logistics\Fleet\Domain\Exception\FleetException;
use Logistics\Fleet\Domain\ValueObject\PlateNumber;

/**
 * Placa: se normaliza a mayúsculas y valida un formato amplio (letras, dígitos y guion opcional).
 */
it('normaliza a mayúsculas y recorta espacios', function (): void {
    $plate = PlateNumber::of('  abc-123  ');

    expect($plate->value)->toBe('ABC-123')
        ->and((string) $plate)->toBe('ABC-123');
});

it('acepta placas sin guion', function (): void {
    expect(PlateNumber::of('1234ABC')->value)->toBe('1234ABC');
});

it('rechaza placas con formato inválido', function (string $value): void {
    expect(fn () => PlateNumber::of($value))
        ->toThrow(FleetException::class, 'Placa con formato inválido');
})->with([
    'muy corta' => ['AB'],
    'con espacios internos' => ['123 ABC'],
    'caracteres no permitidos' => ['123/ABC'],
    'vacía' => [''],
]);

it('compara dos placas por su valor normalizado', function (): void {
    expect(PlateNumber::of('abc-123')->equals(PlateNumber::of('ABC-123')))->toBeTrue()
        ->and(PlateNumber::of('ABC-123')->equals(PlateNumber::of('XYZ-789')))->toBeFalse();
});
