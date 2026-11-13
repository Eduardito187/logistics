<?php

declare(strict_types=1);

use Logistics\Sellers\Domain\Exception\SellersException;
use Logistics\Sellers\Domain\ValueObject\Money;

/**
 * Cobertura del value object Money del contexto sellers: construcción, aritmética segura en centavos
 * (suma/resta/multiplicación), igualdad y serialización primitiva. PHP puro, sin Laravel.
 */
it('construye en BOB por defecto y normaliza la moneda a mayúsculas sin espacios', function (): void {
    $defecto = Money::of(1500);
    expect($defecto->currency)->toBe('BOB')
        ->and($defecto->cents)->toBe(1500);

    $normalizada = Money::of(100, '  usd ');
    expect($normalizada->currency)->toBe('USD');
});

it('rechaza montos negativos y monedas vacías', function (): void {
    expect(fn () => Money::of(-1))->toThrow(SellersException::class);
    expect(fn () => Money::of(100, '   '))->toThrow(SellersException::class);
});

it('suma montos de la misma moneda y rechaza sumar monedas distintas', function (): void {
    expect(Money::of(1500)->add(Money::of(500))->cents)->toBe(2000);
    expect(fn () => Money::of(100, 'BOB')->add(Money::of(100, 'USD')))
        ->toThrow(SellersException::class);
});

it('resta de forma segura y falla si el resultado quedaría negativo', function (): void {
    expect(Money::of(1500)->subtract(Money::of(500))->cents)->toBe(1000);
    expect(fn () => Money::of(100)->subtract(Money::of(300)))->toThrow(SellersException::class);
    expect(fn () => Money::of(100, 'BOB')->subtract(Money::of(1, 'USD')))
        ->toThrow(SellersException::class);
});

it('multiplica por un factor y rechaza factores negativos', function (): void {
    expect(Money::of(1500)->multiply(3)->cents)->toBe(4500);
    expect(Money::of(1500)->multiply(0)->cents)->toBe(0);
    expect(fn () => Money::of(1500)->multiply(-2))->toThrow(SellersException::class);
});

it('compara por valor y expone su forma primitiva', function (): void {
    expect(Money::of(1500)->equals(Money::of(1500)))->toBeTrue()
        ->and(Money::of(1500)->equals(Money::of(1501)))->toBeFalse()
        ->and(Money::of(1500, 'BOB')->equals(Money::of(1500, 'USD')))->toBeFalse()
        ->and(Money::of(1500, 'USD')->toArray())->toBe(['cents' => 1500, 'currency' => 'USD']);
});
