<?php

declare(strict_types=1);

use Logistics\FleetOps\Domain\Exception\FleetOpsException;
use Logistics\FleetOps\Domain\ValueObject\Money;

it('guarda el monto en centavos y normaliza la moneda a mayúsculas', function (): void {
    $money = Money::of(12550, 'bob');

    expect($money->cents)->toBe(12550)
        ->and($money->currency)->toBe('BOB')
        ->and($money->toArray())->toBe(['cents' => 12550, 'currency' => 'BOB']);
});

it('usa BOB como moneda por defecto', function (): void {
    expect(Money::of(100)->currency)->toBe('BOB')
        ->and(Money::zero()->cents)->toBe(0);
});

it('rechaza montos negativos', function (): void {
    expect(fn () => Money::of(-1))->toThrow(FleetOpsException::class, 'no puede ser negativo');
});

it('rechaza moneda vacía', function (): void {
    expect(fn () => Money::of(100, '  '))->toThrow(FleetOpsException::class, 'moneda no puede estar vacía');
});

it('suma y resta montos de la misma moneda', function (): void {
    $a = Money::of(1000);
    $b = Money::of(250);

    expect($a->add($b)->cents)->toBe(1250)
        ->and($a->subtract($b)->cents)->toBe(750);
});

it('multiplica por un factor y redondea a centavos', function (): void {
    // 372 centavos/litro × 50 litros = 18600.
    expect(Money::of(372)->multiply(50.0)->cents)->toBe(18600)
        // 333 × 1.5 = 499.5 → redondea a 500.
        ->and(Money::of(333)->multiply(1.5)->cents)->toBe(500);
});

it('rechaza multiplicar por un factor negativo', function (): void {
    expect(fn () => Money::of(100)->multiply(-1.0))->toThrow(FleetOpsException::class, 'factor no puede ser negativo');
});

it('no permite operar montos de distinta moneda', function (): void {
    $bob = Money::of(1000, 'BOB');
    $usd = Money::of(1000, 'USD');

    expect(fn () => $bob->add($usd))->toThrow(FleetOpsException::class, 'distinta moneda');
});

it('no permite que una resta dé negativo', function (): void {
    $a = Money::of(100);
    $b = Money::of(250);

    expect(fn () => $a->subtract($b))->toThrow(FleetOpsException::class, 'no puede dar negativo');
});

it('compara montos e identifica igualdad', function (): void {
    expect(Money::of(900)->isGreaterThan(Money::of(500)))->toBeTrue()
        ->and(Money::of(500)->equals(Money::of(500)))->toBeTrue()
        ->and(Money::of(500)->equals(Money::of(500, 'USD')))->toBeFalse();
});
