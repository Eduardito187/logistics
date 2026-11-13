<?php

declare(strict_types=1);

use Logistics\SapConnector\Domain\Exception\SapConnectorException;
use Logistics\SapConnector\Domain\ValueObject\SapMaterial;

/**
 * Código de material SAP: value object que solo valida la forma (no vacío, longitud). PHP puro.
 */

it('crea un material recortando los espacios de los extremos', function (): void {
    $material = SapMaterial::of('  MAT-100  ');

    expect($material->value)->toBe('MAT-100')
        ->and((string) $material)->toBe('MAT-100');
});

it('rechaza un material vacío o en blanco', function (): void {
    expect(fn () => SapMaterial::of('   '))
        ->toThrow(SapConnectorException::class, 'no puede estar vacío');
});

it('rechaza un material que excede la longitud máxima', function (): void {
    expect(fn () => SapMaterial::of(str_repeat('M', 41)))
        ->toThrow(SapConnectorException::class, 'excede');
});

it('acepta el material en el límite exacto de longitud', function (): void {
    expect(SapMaterial::of(str_repeat('M', 40))->value)->toBe(str_repeat('M', 40));
});

it('compara dos materiales por su valor', function (): void {
    expect(SapMaterial::of('MAT-1')->equals(SapMaterial::of('MAT-1')))->toBeTrue()
        ->and(SapMaterial::of('MAT-1')->equals(SapMaterial::of('MAT-2')))->toBeFalse();
});
