<?php

declare(strict_types=1);

use Logistics\Settlement\Domain\Exception\SettlementException;
use Logistics\Settlement\Domain\ValueObject\Money;

it('crea montos en centavos con moneda por defecto BOB', function (): void {
    $money = Money::of(1500);

    expect($money->cents)->toBe(1500)
        ->and($money->currency)->toBe('BOB');
});

it('normaliza la moneda a mayúsculas y rechaza negativos', function (): void {
    expect(Money::of(100, 'usd')->currency)->toBe('USD');
    expect(fn () => Money::of(-1))->toThrow(SettlementException::class);
});

it('suma, resta y multiplica montos de la misma moneda', function (): void {
    $a = Money::of(1000);
    $b = Money::of(250);

    expect($a->add($b)->cents)->toBe(1250)
        ->and($a->subtract($b)->cents)->toBe(750)
        ->and($a->multiply(3)->cents)->toBe(3000);
});

it('falla al restar por debajo de cero o al operar monedas distintas', function (): void {
    expect(fn () => Money::of(100)->subtract(Money::of(200)))->toThrow(SettlementException::class);
    expect(fn () => Money::of(100, 'BOB')->add(Money::of(100, 'USD')))->toThrow(SettlementException::class);
});

it('aplica una tasa en basis points truncando al centavo', function (): void {
    // 15 % de 1.000.000 = 150.000; 1234 bp de 10.001 = 1234,12... → trunca a 1234.
    expect(Money::of(1_000_000)->percentageByBasisPoints(1500)->cents)->toBe(150_000)
        ->and(Money::of(10_001)->percentageByBasisPoints(1234)->cents)->toBe(1234);
});

it('expone zero y compara con isGreaterThan y equals', function (): void {
    expect(Money::zero()->cents)->toBe(0)
        ->and(Money::of(200)->isGreaterThan(Money::of(100)))->toBeTrue()
        ->and(Money::of(100)->equals(Money::of(100)))->toBeTrue();
});
