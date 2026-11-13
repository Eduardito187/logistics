<?php

declare(strict_types=1);

use Logistics\Compensation\Domain\Exception\CompensationException;
use Logistics\Compensation\Domain\ValueObject\Money;

// Monto en centavos + moneda, sin floats: topes y sumas exactas.

it('construye un monto y normaliza la moneda a mayúsculas', function (): void {
    $money = Money::of(15_000, 'bob');

    expect($money->cents)->toBe(15_000)
        ->and($money->currency)->toBe('BOB')
        ->and($money->isBob())->toBeTrue();
});

it('rechaza un monto negativo', function (): void {
    expect(fn () => Money::of(-1))->toThrow(CompensationException::class);
});

it('rechaza una moneda vacía', function (): void {
    expect(fn () => Money::of(100, '  '))->toThrow(CompensationException::class);
});

it('crea el monto cero', function (): void {
    expect(Money::zero()->isZero())->toBeTrue()
        ->and(Money::zero('USD')->currency)->toBe('USD');
});

it('devuelve el menor de dos montos', function (): void {
    $a = Money::of(3_000);
    $b = Money::of(5_000);

    expect($a->min($b)->cents)->toBe(3_000)
        ->and($b->min($a)->cents)->toBe(3_000);
});

it('suma dos montos de la misma moneda', function (): void {
    expect(Money::of(1_000)->add(Money::of(500))->cents)->toBe(1_500);
});

it('resta con piso en cero', function (): void {
    expect(Money::of(500)->subtractFloored(Money::of(800))->cents)->toBe(0)
        ->and(Money::of(800)->subtractFloored(Money::of(300))->cents)->toBe(500);
});

it('redondea siempre hacia abajo al múltiplo pedido', function (): void {
    expect(Money::of(15_745)->floorTo(100)->cents)->toBe(15_700)
        ->and(Money::of(15_745)->floorTo(1)->cents)->toBe(15_745)
        ->and(Money::of(15_745)->floorTo(0)->cents)->toBe(15_745);
});

it('compara montos', function (): void {
    expect(Money::of(100)->isLessThan(Money::of(200)))->toBeTrue()
        ->and(Money::of(200)->isLessThan(Money::of(100)))->toBeFalse()
        ->and(Money::of(100)->equals(Money::of(100)))->toBeTrue()
        ->and(Money::of(100)->equals(Money::of(100, 'USD')))->toBeFalse();
});

it('formatea de forma legible y nunca vacía', function (): void {
    expect(Money::of(15_700)->formatted())->toBe('Bs 157,00');
});

it('expone su forma primitiva', function (): void {
    expect(Money::of(15_000, 'USD')->toArray())->toBe(['cents' => 15_000, 'currency' => 'USD']);
});

it('rechaza operar montos de distinta moneda', function (): void {
    expect(fn () => Money::of(100)->add(Money::of(100, 'USD')))->toThrow(CompensationException::class);
    expect(fn () => Money::of(100)->min(Money::of(100, 'USD')))->toThrow(CompensationException::class);
    expect(fn () => Money::of(100)->isLessThan(Money::of(100, 'USD')))->toThrow(CompensationException::class);
});
