<?php

declare(strict_types=1);

use Logistics\Compensation\Domain\ValueObject\CalculationResult;
use Logistics\Compensation\Domain\ValueObject\CalculationTrace;
use Logistics\Compensation\Domain\ValueObject\Money;

// Resultado del cálculo del vale: nominal, efectivo, compensable y la traza de auditoría.

it('arma un resultado compensable', function (): void {
    $trace = CalculationTrace::empty()->with('base', Money::of(20_000));
    $result = CalculationResult::compensable(Money::of(3_000), Money::of(3_000), $trace);

    expect($result->compensable)->toBeTrue()
        ->and($result->nominal->cents)->toBe(3_000)
        ->and($result->effective->cents)->toBe(3_000)
        ->and($result->notCompensableReason)->toBeNull();
});

it('arma un resultado no compensable con efectivo cero y motivo', function (): void {
    $trace = CalculationTrace::empty();
    $result = CalculationResult::notCompensable(Money::of(500), 'E < mínimo', $trace);

    expect($result->compensable)->toBeFalse()
        ->and($result->effective->isZero())->toBeTrue()
        ->and($result->effective->currency)->toBe('BOB')
        ->and($result->notCompensableReason)->toBe('E < mínimo');
});

it('la traza acumula pasos en orden', function (): void {
    $trace = CalculationTrace::empty()
        ->with('base', Money::of(20_000))
        ->with('nominal', Money::of(3_000));

    expect($trace->toArray())->toBe([
        ['label' => 'base', 'cents' => 20_000],
        ['label' => 'nominal', 'cents' => 3_000],
    ]);
});
