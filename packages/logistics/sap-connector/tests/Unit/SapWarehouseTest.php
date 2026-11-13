<?php

declare(strict_types=1);

use Logistics\SapConnector\Domain\Exception\SapConnectorException;
use Logistics\SapConnector\Domain\ValueObject\SapWarehouse;

/**
 * Almacén SAP (Lagerort): value object que solo valida la forma del código. PHP puro.
 */
it('crea un almacén recortando los espacios de los extremos', function (): void {
    $warehouse = SapWarehouse::of('  A01  ');

    expect($warehouse->value)->toBe('A01')
        ->and((string) $warehouse)->toBe('A01');
});

it('rechaza un almacén vacío o en blanco', function (): void {
    expect(fn () => SapWarehouse::of('   '))
        ->toThrow(SapConnectorException::class, 'no puede estar vacío');
});

it('rechaza un almacén que excede la longitud máxima', function (): void {
    expect(fn () => SapWarehouse::of(str_repeat('A', 9)))
        ->toThrow(SapConnectorException::class, 'excede');
});

it('acepta el almacén en el límite exacto de longitud', function (): void {
    expect(SapWarehouse::of(str_repeat('A', 8))->value)->toBe(str_repeat('A', 8));
});

it('compara dos almacenes por su valor', function (): void {
    expect(SapWarehouse::of('A01')->equals(SapWarehouse::of('A01')))->toBeTrue()
        ->and(SapWarehouse::of('A01')->equals(SapWarehouse::of('A02')))->toBeFalse();
});
