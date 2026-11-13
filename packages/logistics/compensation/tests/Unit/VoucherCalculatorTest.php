<?php

declare(strict_types=1);

use Logistics\Compensation\Domain\Service\VoucherCalculator;
use Logistics\Compensation\Domain\ValueObject\AffectedLine;
use Logistics\Compensation\Domain\ValueObject\AffectedValue;
use Logistics\Compensation\Domain\ValueObject\CompensationPolicy;
use Logistics\Compensation\Domain\ValueObject\Money;
use Logistics\Compensation\Domain\ValueObject\TriggerType;

// Servicio de dominio puro: aplica topes y redondeo y nunca emite un vale mayor al valor afectado.

function calcPolicy(array $triggerOverrides = [], array $globalOverrides = []): CompensationPolicy
{
    $trigger = array_merge([
        'enabled' => true,
        'method' => 'percentage',
        'value' => 1_500,
        'max_pct' => 5_000,
        'abs_cap' => 50_000,
        'auto_approve' => true,
        'validity_days' => 90,
    ], $triggerOverrides);

    return CompensationPolicy::fromArray(array_merge([
        'cumulative_pct' => 4_000,
        'rounding_step' => 100,
        'min_amount' => 1_000,
        'auto_approve_max' => 5_000,
        'level1_max' => 30_000,
        'triggers' => ['DAMAGED_PRODUCT' => $trigger],
    ], $globalOverrides));
}

function calcAffected(int $baseCents, ?int $orderBaseCents = null): AffectedValue
{
    $line = AffectedLine::of('Producto', 'SKU-1', 1, Money::of($baseCents));

    return AffectedValue::fromLines([$line], $orderBaseCents !== null ? Money::of($orderBaseCents) : null);
}

it('calcula el efectivo por porcentaje con redondeo hacia abajo', function (): void {
    // Bs 200 × 15 % = Bs 30.
    $result = (new VoucherCalculator)->calculate(
        TriggerType::DamagedProduct,
        calcAffected(20_000),
        calcPolicy(),
        Money::zero(),
    );

    expect($result->compensable)->toBeTrue()
        ->and($result->nominal->cents)->toBe(3_000)
        ->and($result->effective->cents)->toBe(3_000);
});

it('calcula el nominal por método fixed', function (): void {
    $result = (new VoucherCalculator)->calculate(
        TriggerType::DamagedProduct,
        calcAffected(100_000),
        calcPolicy(['method' => 'fixed', 'value' => 7_000]),
        Money::zero(),
    );

    expect($result->nominal->cents)->toBe(7_000)
        ->and($result->effective->cents)->toBe(7_000);
});

it('no es compensable si el disparador está deshabilitado', function (): void {
    $result = (new VoucherCalculator)->calculate(
        TriggerType::DamagedProduct,
        calcAffected(100_000),
        calcPolicy(['enabled' => false]),
        Money::zero(),
    );

    expect($result->compensable)->toBeFalse()
        ->and($result->effective->isZero())->toBeTrue()
        ->and($result->notCompensableReason)->not->toBeNull();
});

it('topa el efectivo por el porcentaje máximo sobre la base', function (): void {
    // value 90 % pero max_pct 50 %: topa a Bs 100. orderBase amplio para que el acumulado no ate.
    $result = (new VoucherCalculator)->calculate(
        TriggerType::DamagedProduct,
        calcAffected(20_000, 1_000_000),
        calcPolicy(['value' => 9_000, 'max_pct' => 5_000, 'abs_cap' => 1_000_000]),
        Money::zero(),
    );

    expect($result->effective->cents)->toBe(10_000);
});

it('topa el efectivo por el tope absoluto', function (): void {
    $result = (new VoucherCalculator)->calculate(
        TriggerType::DamagedProduct,
        calcAffected(100_000),
        calcPolicy(['value' => 9_000, 'max_pct' => 10_000, 'abs_cap' => 2_000]),
        Money::zero(),
    );

    expect($result->effective->cents)->toBe(2_000);
});

it('topa el efectivo por el acumulado de la orden, descontando lo ya emitido', function (): void {
    // orderBase Bs 1 000, cumulative 40 % = Bs 400; ya emitido Bs 350 → queda Bs 50.
    $result = (new VoucherCalculator)->calculate(
        TriggerType::DamagedProduct,
        calcAffected(100_000, 100_000),
        calcPolicy(['value' => 9_000, 'max_pct' => 10_000, 'abs_cap' => 1_000_000]),
        Money::of(35_000),
    );

    expect($result->effective->cents)->toBe(5_000);
});

it('no es compensable si el efectivo cae por debajo del mínimo', function (): void {
    // Bs 100 × 15 % = Bs 15 < mínimo Bs 10? no. Usar base chica: Bs 50 × 15 % = Bs 7,5 → floor Bs 7 < Bs 10.
    $result = (new VoucherCalculator)->calculate(
        TriggerType::DamagedProduct,
        calcAffected(5_000),
        calcPolicy(),
        Money::zero(),
    );

    expect($result->compensable)->toBeFalse()
        ->and($result->effective->isZero())->toBeTrue()
        ->and($result->notCompensableReason)->toContain('mínimo');
});
