<?php

declare(strict_types=1);

use Logistics\Compensation\Domain\Exception\CompensationException;
use Logistics\Compensation\Domain\ValueObject\CompensationPolicy;
use Logistics\Compensation\Domain\ValueObject\TriggerType;

// Política completa: topes globales + una TriggerPolicy por disparador; su forma primitiva es cacheable.

function fullPolicyArray(): array
{
    return [
        'cumulative_pct' => 4_000,
        'rounding_step' => 100,
        'min_amount' => 1_000,
        'auto_approve_max' => 5_000,
        'level1_max' => 30_000,
        'approval_sla_hours' => 24,
        'late_tolerance_minutes' => 60,
        'currency' => 'BOB',
        'low_rating_threshold' => 3,
        'triggers' => [
            'damaged_product' => ['method' => 'percentage', 'value' => 1_500, 'auto_approve' => true],
        ],
        'compensable_failure_reasons' => ['damaged', ' OTHER ', 'damaged'],
    ];
}

it('arma la política desde array con todos los campos', function (): void {
    $policy = CompensationPolicy::fromArray(fullPolicyArray());

    expect($policy->cumulativePctBps)->toBe(4_000)
        ->and($policy->roundingStepCents)->toBe(100)
        ->and($policy->minAmountCents)->toBe(1_000)
        ->and($policy->autoApproveMaxCents)->toBe(5_000)
        ->and($policy->level1MaxCents)->toBe(30_000)
        ->and($policy->lateToleranceMinutes)->toBe(60)
        ->and($policy->lowRatingThreshold)->toBe(3)
        ->and($policy->currency)->toBe('BOB');
});

it('normaliza la clave del disparador a mayúsculas', function (): void {
    $policy = CompensationPolicy::fromArray(fullPolicyArray());

    expect($policy->forTrigger(TriggerType::DamagedProduct)->value)->toBe(1_500);
});

it('arma con defaults cuando el array viene vacío', function (): void {
    $policy = CompensationPolicy::fromArray([]);

    expect($policy->cumulativePctBps)->toBe(4_000)
        ->and($policy->autoApproveMaxCents)->toBe(5_000)
        ->and($policy->level1MaxCents)->toBe(30_000)
        ->and($policy->currency)->toBe('BOB')
        ->and($policy->triggers)->toBe([])
        ->and($policy->compensableFailures)->toBe([]);
});

it('fuerza pisos mínimos en pasos de redondeo, SLA y tolerancia', function (): void {
    $policy = CompensationPolicy::fromArray([
        'rounding_step' => 0,
        'approval_sla_hours' => 0,
        'late_tolerance_minutes' => -5,
    ]);

    expect($policy->roundingStepCents)->toBe(1)
        ->and($policy->approvalSlaHours)->toBe(1)
        ->and($policy->lateToleranceMinutes)->toBe(0);
});

it('rechaza level1_max menor que auto_approve_max', function (): void {
    expect(fn () => CompensationPolicy::fromArray(['level1_max' => 1_000, 'auto_approve_max' => 5_000]))
        ->toThrow(CompensationException::class);
});

it('deduplica y normaliza los motivos de fallida compensables', function (): void {
    $policy = CompensationPolicy::fromArray(fullPolicyArray());

    expect($policy->compensableFailures)->toBe(['DAMAGED', 'OTHER'])
        ->and($policy->isFailureCompensable('damaged'))->toBeTrue()
        ->and($policy->isFailureCompensable(' other '))->toBeTrue()
        ->and($policy->isFailureCompensable('customer_absent'))->toBeFalse();
});

it('lanza si se pide un disparador sin política', function (): void {
    expect(fn () => CompensationPolicy::fromArray([])->forTrigger(TriggerType::LowRating))
        ->toThrow(CompensationException::class);
});

it('expone los topes como Money', function (): void {
    $policy = CompensationPolicy::fromArray(fullPolicyArray());

    expect($policy->autoApproveMax()->cents)->toBe(5_000)
        ->and($policy->level1Max()->cents)->toBe(30_000)
        ->and($policy->minAmount()->cents)->toBe(1_000)
        ->and($policy->autoApproveMax()->currency)->toBe('BOB');
});
