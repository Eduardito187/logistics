<?php

declare(strict_types=1);

use Logistics\Fleet\Domain\Exception\FleetException;
use Logistics\Fleet\Domain\ValueObject\LicenseNumber;

/**
 * Número de licencia: se normaliza a mayúsculas y valida formato alfanumérico.
 */
it('normaliza a mayúsculas y recorta espacios', function (): void {
    $license = LicenseNumber::of('  lic-00123  ');

    expect($license->value)->toBe('LIC-00123')
        ->and((string) $license)->toBe('LIC-00123');
});

it('rechaza números de licencia con formato inválido', function (string $value): void {
    expect(fn () => LicenseNumber::of($value))
        ->toThrow(FleetException::class, 'Número de licencia inválido');
})->with([
    'muy corto' => ['AB'],
    'con espacios internos' => ['LIC 00123'],
    'caracteres no permitidos' => ['LIC/0012*'],
    'vacío' => [''],
]);

it('compara dos licencias por su valor normalizado', function (): void {
    expect(LicenseNumber::of('lic-1')->equals(LicenseNumber::of('LIC-1')))->toBeTrue()
        ->and(LicenseNumber::of('LIC-1')->equals(LicenseNumber::of('LIC-2')))->toBeFalse();
});
