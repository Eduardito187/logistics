<?php

declare(strict_types=1);

use Logistics\SapConnector\Domain\Exception\SapConnectorException;
use Logistics\SapConnector\Domain\ValueObject\SapCenter;

/**
 * Centro SAP (Werk): value object que solo valida la forma del código. PHP puro.
 */
it('crea un centro recortando los espacios de los extremos', function (): void {
    $center = SapCenter::of('  C001  ');

    expect($center->value)->toBe('C001')
        ->and((string) $center)->toBe('C001');
});

it('rechaza un centro vacío o en blanco', function (): void {
    expect(fn () => SapCenter::of('   '))
        ->toThrow(SapConnectorException::class, 'no puede estar vacío');
});

it('rechaza un centro que excede la longitud máxima', function (): void {
    expect(fn () => SapCenter::of(str_repeat('C', 9)))
        ->toThrow(SapConnectorException::class, 'excede');
});

it('acepta el centro en el límite exacto de longitud', function (): void {
    expect(SapCenter::of(str_repeat('C', 8))->value)->toBe(str_repeat('C', 8));
});

it('compara dos centros por su valor', function (): void {
    expect(SapCenter::of('C001')->equals(SapCenter::of('C001')))->toBeTrue()
        ->and(SapCenter::of('C001')->equals(SapCenter::of('C002')))->toBeFalse();
});
