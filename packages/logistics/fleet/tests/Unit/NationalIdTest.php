<?php

declare(strict_types=1);

use Logistics\Fleet\Domain\Exception\FleetException;
use Logistics\Fleet\Domain\ValueObject\NationalId;

/**
 * Documento de identidad: se recorta el espacio, no se impone un formato nacional rígido.
 */
it('recorta espacios y conserva el formato tal cual', function (): void {
    $id = NationalId::of('  12345678-1A  ');

    expect($id->value)->toBe('12345678-1A')
        ->and((string) $id)->toBe('12345678-1A');
});

it('rechaza un documento vacío', function (): void {
    expect(fn () => NationalId::of('   '))
        ->toThrow(FleetException::class, 'no puede estar vacío');
});

it('compara dos documentos por su valor', function (): void {
    expect(NationalId::of('CI-1')->equals(NationalId::of('CI-1')))->toBeTrue()
        ->and(NationalId::of('CI-1')->equals(NationalId::of('CI-2')))->toBeFalse();
});
