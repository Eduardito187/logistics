<?php

declare(strict_types=1);

use Logistics\Compensation\Domain\Exception\CompensationException;
use Logistics\Compensation\Domain\ValueObject\AffectedLine;
use Logistics\Compensation\Domain\ValueObject\AffectedValue;
use Logistics\Compensation\Domain\ValueObject\Money;

// Base del cálculo a partir de las líneas afectadas (o base declarada manualmente).

function avLine(int $cents, int $qty = 1, string $currency = 'BOB'): AffectedLine
{
    return AffectedLine::of('Producto', 'SKU-'.$cents, $qty, Money::of($cents, $currency));
}

it('arma la base sumando las líneas; orderBase cae a la base cuando no se pasa', function (): void {
    $value = AffectedValue::fromLines([avLine(20_000), avLine(5_000, 2)]);

    expect($value->base->cents)->toBe(30_000)
        ->and($value->orderBase->cents)->toBe(30_000)
        ->and($value->hasDeclaredBase)->toBeFalse()
        ->and($value->lines)->toHaveCount(2);
});

it('acepta un orderBase mayor o igual a la base', function (): void {
    $value = AffectedValue::fromLines([avLine(10_000)], Money::of(40_000));

    expect($value->orderBase->cents)->toBe(40_000);
});

it('rechaza una lista de líneas vacía', function (): void {
    expect(fn () => AffectedValue::fromLines([]))->toThrow(CompensationException::class);
});

it('rechaza emisión automática si alguna línea no es BOB', function (): void {
    expect(fn () => AffectedValue::fromLines([avLine(10_000, 1, 'USD')]))
        ->toThrow(CompensationException::class);
});

it('rechaza un orderBase menor a la base afectada', function (): void {
    expect(fn () => AffectedValue::fromLines([avLine(50_000)], Money::of(10_000)))
        ->toThrow(CompensationException::class);
});

it('arma una base declarada manualmente (regla 6)', function (): void {
    $value = AffectedValue::manual(Money::of(100_000));

    expect($value->base->cents)->toBe(100_000)
        ->and($value->orderBase->cents)->toBe(100_000)
        ->and($value->hasDeclaredBase)->toBeTrue()
        ->and($value->lines)->toBe([]);
});

it('la base manual rechaza un orderBase menor que la base', function (): void {
    expect(fn () => AffectedValue::manual(Money::of(100_000), Money::of(10_000)))
        ->toThrow(CompensationException::class);
});

it('expone las líneas como array', function (): void {
    $value = AffectedValue::fromLines([avLine(20_000)]);

    expect($value->linesToArray())->toHaveCount(1)
        ->and($value->linesToArray()[0]['unit_price_cents'])->toBe(20_000);
});
