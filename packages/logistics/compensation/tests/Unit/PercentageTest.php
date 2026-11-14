<?php

declare(strict_types=1);

use Logistics\Compensation\Domain\Exception\CompensationException;
use Logistics\Compensation\Domain\ValueObject\Money;
use Logistics\Compensation\Domain\ValueObject\Percentage;

// Porcentaje en basis points (0..10000); aplica con floor, nunca regala de más.

it('construye un porcentaje dentro del rango', function (): void {
    expect(Percentage::ofBasisPoints(1_500)->bps)->toBe(1_500);
});

it('rechaza un porcentaje fuera de rango', function (): void {
    expect(fn () => Percentage::ofBasisPoints(-1))->toThrow(CompensationException::class);
    expect(fn () => Percentage::ofBasisPoints(Percentage::MAX_BPS + 1))->toThrow(CompensationException::class);
});

it('aplica el porcentaje redondeando hacia abajo', function (): void {
    // Bs 1 049,70 × 15 % = Bs 157,455 → floor a 157,45.
    expect(Percentage::ofBasisPoints(1_500)->applyTo(Money::of(104_970))->cents)->toBe(15_745);
});

it('preserva la moneda de la base', function (): void {
    expect(Percentage::ofBasisPoints(5_000)->applyTo(Money::of(1_000, 'USD'))->currency)->toBe('USD');
});
