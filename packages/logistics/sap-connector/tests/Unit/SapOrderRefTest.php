<?php

declare(strict_types=1);

use Logistics\SapConnector\Domain\Exception\SapConnectorException;
use Logistics\SapConnector\Domain\ValueObject\SapOrderRef;

/**
 * Referencia de pedido en SAP: value object que solo valida la forma. PHP puro.
 */

it('crea una referencia recortando los espacios de los extremos', function (): void {
    $ref = SapOrderRef::of('  SO-12345  ');

    expect($ref->value)->toBe('SO-12345')
        ->and((string) $ref)->toBe('SO-12345');
});

it('rechaza una referencia vacía o en blanco', function (): void {
    expect(fn () => SapOrderRef::of('   '))
        ->toThrow(SapConnectorException::class, 'no puede estar vacía');
});

it('rechaza una referencia que excede la longitud máxima', function (): void {
    expect(fn () => SapOrderRef::of(str_repeat('9', 33)))
        ->toThrow(SapConnectorException::class, 'excede');
});

it('acepta la referencia en el límite exacto de longitud', function (): void {
    expect(SapOrderRef::of(str_repeat('9', 32))->value)->toBe(str_repeat('9', 32));
});

it('compara dos referencias por su valor', function (): void {
    expect(SapOrderRef::of('SO-1')->equals(SapOrderRef::of('SO-1')))->toBeTrue()
        ->and(SapOrderRef::of('SO-1')->equals(SapOrderRef::of('SO-2')))->toBeFalse();
});
