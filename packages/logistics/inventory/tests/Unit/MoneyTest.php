<?php

declare(strict_types=1);

use Logistics\Inventory\Domain\Exception\InventoryException;
use Logistics\Inventory\Domain\ValueObject\Money;

/**
 * VO Money del contexto Inventory: entero en centavos + moneda, inmutable. Cubre construcción, moneda por
 * defecto/normalizada, multiplicación, igualdad, forma primitiva y las excepciones de dominio propias.
 */
it('construye un monto con la moneda por defecto BOB', function (): void {
    $money = Money::of(180000);

    expect($money->cents)->toBe(180000)
        ->and($money->currency)->toBe('BOB')
        ->and($money->currency)->toBe(Money::DEFAULT_CURRENCY);
});

it('normaliza la moneda a mayúsculas y sin espacios', function (): void {
    expect(Money::of(100, ' usd ')->currency)->toBe('USD');
});

it('acepta el monto cero', function (): void {
    expect(Money::of(0)->cents)->toBe(0);
});

it('rechaza un monto negativo con la excepción del contexto', function (): void {
    expect(fn () => Money::of(-1))->toThrow(InventoryException::class, 'negativo');
});

it('rechaza una moneda vacía', function (): void {
    expect(fn () => Money::of(100, '   '))->toThrow(InventoryException::class, 'moneda');
});

it('multiplica conservando la moneda', function (): void {
    $result = Money::of(2500, 'USD')->multiply(4);

    expect($result->cents)->toBe(10000)
        ->and($result->currency)->toBe('USD');
});

it('multiplicar por cero da un monto nulo', function (): void {
    expect(Money::of(999)->multiply(0)->cents)->toBe(0);
});

it('rechaza un factor negativo al multiplicar', function (): void {
    expect(fn () => Money::of(100)->multiply(-2))->toThrow(InventoryException::class, 'factor');
});

it('compara por valor: mismos centavos y misma moneda', function (): void {
    expect(Money::of(100)->equals(Money::of(100)))->toBeTrue()
        ->and(Money::of(100)->equals(Money::of(101)))->toBeFalse()
        ->and(Money::of(100, 'BOB')->equals(Money::of(100, 'USD')))->toBeFalse();
});

it('exporta su forma primitiva', function (): void {
    expect(Money::of(320000, 'USD')->toArray())->toBe(['cents' => 320000, 'currency' => 'USD']);
});
