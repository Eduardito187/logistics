<?php

declare(strict_types=1);

use Logistics\Capacity\Domain\Exception\CapacityException;
use Logistics\Capacity\Domain\ValueObject\Quantity;

it('builds a non-negative quantity and exposes its value', function (): void {
    expect(Quantity::of(12.5)->value())->toBe(12.5)
        ->and(Quantity::zero()->value())->toBe(0.0)
        ->and(Quantity::zero()->isZero())->toBeTrue();
});

it('rejects a negative quantity', function (): void {
    expect(fn () => Quantity::of(-1.0))->toThrow(CapacityException::class, 'negativa');
});

it('rejects a negative quantity from thousandths', function (): void {
    expect(fn () => Quantity::fromThousandths(-5))->toThrow(CapacityException::class, 'negativa');
});

it('adds two quantities exactly', function (): void {
    expect(Quantity::of(1.5)->add(Quantity::of(2.25))->value())->toBe(3.75);
});

it('subtracts and never drops below zero', function (): void {
    expect(Quantity::of(5.0)->subtract(Quantity::of(3.0))->value())->toBe(2.0)
        ->and(Quantity::of(3.0)->subtract(Quantity::of(5.0))->value())->toBe(0.0)
        ->and(Quantity::of(3.0)->subtract(Quantity::of(5.0))->isZero())->toBeTrue();
});

it('keeps three-decimal precision without float drift', function (): void {
    // 0.1 + 0.2 daría 0.30000000000000004 en flotante; con milésimas internas es exacto.
    expect(Quantity::of(0.1)->add(Quantity::of(0.2))->value())->toBe(0.3);
});

it('compares quantities', function (): void {
    expect(Quantity::of(5.0)->isGreaterThan(Quantity::of(4.999)))->toBeTrue()
        ->and(Quantity::of(5.0)->isGreaterThan(Quantity::of(5.0)))->toBeFalse()
        ->and(Quantity::of(5.0)->equals(Quantity::of(5.0)))->toBeTrue();
});
