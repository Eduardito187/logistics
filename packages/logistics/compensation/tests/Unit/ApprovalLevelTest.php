<?php

declare(strict_types=1);

use Logistics\Compensation\Domain\ValueObject\ApprovalLevel;
use Logistics\Compensation\Domain\ValueObject\Money;

// Nivel de aprobación según el monto efectivo, los umbrales y si el disparador admite auto-aprobación.

it('decide AUTO con disparador auto-aprobable y monto bajo', function (): void {
    $level = ApprovalLevel::decide(Money::of(3_000), Money::of(5_000), Money::of(30_000), true);

    expect($level)->toBe(ApprovalLevel::Auto);
});

it('no decide AUTO si el disparador no admite auto-aprobación', function (): void {
    $level = ApprovalLevel::decide(Money::of(3_000), Money::of(5_000), Money::of(30_000), false);

    expect($level)->toBe(ApprovalLevel::Level1);
});

it('decide LEVEL1 cuando supera el tope de auto pero no el de level1', function (): void {
    $level = ApprovalLevel::decide(Money::of(15_000), Money::of(5_000), Money::of(30_000), true);

    expect($level)->toBe(ApprovalLevel::Level1);
});

it('decide LEVEL2 cuando supera el tope de level1', function (): void {
    $level = ApprovalLevel::decide(Money::of(40_000), Money::of(5_000), Money::of(30_000), true);

    expect($level)->toBe(ApprovalLevel::Level2);
});
