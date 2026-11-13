<?php

declare(strict_types=1);

use Logistics\Compensation\Domain\Exception\CompensationException;
use Logistics\Compensation\Domain\ValueObject\Percentage;
use Logistics\Compensation\Domain\ValueObject\TriggerPolicy;

// Política de un disparador: método, valor, topes, auto-aprobación y vigencia.

it('construye una política de porcentaje válida', function (): void {
    $policy = TriggerPolicy::of(true, 'PERCENTAGE', 1_500, 5_000, 50_000, true, 90);

    expect($policy->enabled)->toBeTrue()
        ->and($policy->method)->toBe(TriggerPolicy::METHOD_PERCENTAGE)
        ->and($policy->isPercentage())->toBeTrue()
        ->and($policy->value)->toBe(1_500)
        ->and($policy->autoApprove)->toBeTrue();
});

it('un método fixed no es porcentaje', function (): void {
    expect(TriggerPolicy::of(true, 'fixed', 0, 10_000, 50_000, false, 90)->isPercentage())->toBeFalse();
});

it('rechaza un método de cálculo inválido', function (): void {
    expect(fn () => TriggerPolicy::of(true, 'cuotas', 0, 0, 0, false, 90))
        ->toThrow(CompensationException::class);
});

it('rechaza un valor negativo', function (): void {
    expect(fn () => TriggerPolicy::of(true, 'fixed', -1, 0, 0, false, 90))
        ->toThrow(CompensationException::class);
});

it('rechaza un max_pct fuera de rango', function (): void {
    expect(fn () => TriggerPolicy::of(true, 'fixed', 0, -1, 0, false, 90))
        ->toThrow(CompensationException::class);
    expect(fn () => TriggerPolicy::of(true, 'fixed', 0, Percentage::MAX_BPS + 1, 0, false, 90))
        ->toThrow(CompensationException::class);
});

it('rechaza un tope absoluto negativo', function (): void {
    expect(fn () => TriggerPolicy::of(true, 'fixed', 0, 0, -1, false, 90))
        ->toThrow(CompensationException::class);
});

it('rechaza vigencia menor a 1 día', function (): void {
    expect(fn () => TriggerPolicy::of(true, 'fixed', 0, 0, 0, false, 0))
        ->toThrow(CompensationException::class);
});

it('arma desde array con defaults y vuelve a array', function (): void {
    $policy = TriggerPolicy::fromArray([]);

    expect($policy->enabled)->toBeTrue()
        ->and($policy->method)->toBe(TriggerPolicy::METHOD_PERCENTAGE)
        ->and($policy->value)->toBe(0)
        ->and($policy->maxPctBps)->toBe(Percentage::MAX_BPS)
        ->and($policy->validityDays)->toBe(90);

    $array = TriggerPolicy::of(false, 'fixed', 10_000, 5_000, 50_000, true, 30)->toArray();
    expect($array)->toBe([
        'enabled' => false,
        'method' => 'fixed',
        'value' => 10_000,
        'max_pct' => 5_000,
        'abs_cap' => 50_000,
        'auto_approve' => true,
        'validity_days' => 30,
    ]);
});
