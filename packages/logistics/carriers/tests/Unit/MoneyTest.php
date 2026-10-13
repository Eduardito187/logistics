<?php

declare(strict_types=1);

use Logistics\Carriers\Domain\Exception\CarriersException;
use Logistics\Carriers\Domain\ValueObject\Money;

it('guarda el monto en centavos y normaliza la moneda a mayúsculas', function (): void {
    $money = Money::of(12550, 'bob');

    expect($money->cents)->toBe(12550)
        ->and($money->currency)->toBe('BOB')
        ->and($money->toArray())->toBe(['cents' => 12550, 'currency' => 'BOB']);
});

it('rechaza montos negativos', function (): void {
    expect(fn () => Money::of(-1, 'BOB'))->toThrow(CarriersException::class, 'no puede ser negativo');
});

it('rechaza moneda vacía', function (): void {
    expect(fn () => Money::of(100, '  '))->toThrow(CarriersException::class, 'moneda no puede estar vacía');
});

it('suma y resta montos de la misma moneda', function (): void {
    $a = Money::of(1000, 'BOB');
    $b = Money::of(250, 'BOB');

    expect($a->add($b)->cents)->toBe(1250)
        ->and($a->subtract($b)->cents)->toBe(750);
});

it('no permite operar montos de distinta moneda', function (): void {
    $bob = Money::of(1000, 'BOB');
    $usd = Money::of(1000, 'USD');

    expect(fn () => $bob->add($usd))->toThrow(CarriersException::class, 'distinta moneda');
});

it('no permite que una resta dé negativo', function (): void {
    $a = Money::of(100, 'BOB');
    $b = Money::of(250, 'BOB');

    expect(fn () => $a->subtract($b))->toThrow(CarriersException::class, 'no puede dar negativo');
});

it('compara montos de la misma moneda', function (): void {
    $cheap = Money::of(500, 'BOB');
    $pricey = Money::of(900, 'BOB');

    expect($cheap->isLessThan($pricey))->toBeTrue()
        ->and($pricey->isGreaterThan($cheap))->toBeTrue()
        ->and($cheap->equals(Money::of(500, 'BOB')))->toBeTrue();
});
