<?php

declare(strict_types=1);

use Logistics\Planning\Domain\Exception\PlanningException;
use Logistics\Planning\Domain\ValueObject\AutoPlanParameters;
use Logistics\Planning\Domain\ValueObject\PlanStrategy;

/**
 * Parámetros válidos de base; cada prueba varía solo el campo que le interesa.
 */
function autoPlanParameters(
    float $weight = 5.0,
    float $volume = 0.1,
    float $slack = 0.1,
    string $departAt = '2026-10-13T07:30:00+00:00',
): AutoPlanParameters {
    return new AutoPlanParameters(
        PlanStrategy::Optimization,
        $departAt,
        $weight,
        $volume,
        $slack,
        'node-cd-1',
    );
}

it('keeps its configured values', function (): void {
    $params = autoPlanParameters();

    expect($params->strategy)->toBe(PlanStrategy::Optimization)
        ->and($params->departAt)->toBe('2026-10-13T07:30:00+00:00')
        ->and($params->defaultWeightKgPerPackage)->toBe(5.0)
        ->and($params->defaultVolumeM3PerPackage)->toBe(0.1)
        ->and($params->capacitySlackRatio)->toBe(0.1)
        ->and($params->originNodeId)->toBe('node-cd-1');
});

it('derives the usable capacity ratio from the slack', function (): void {
    expect(autoPlanParameters(slack: 0.1)->usableCapacityRatio())->toEqualWithDelta(0.9, 1e-9)
        ->and(autoPlanParameters(slack: 0.0)->usableCapacityRatio())->toEqualWithDelta(1.0, 1e-9);
});

it('rejects a non-positive default weight per package', function (): void {
    expect(fn () => autoPlanParameters(weight: 0.0))
        ->toThrow(PlanningException::class, 'peso por producto por defecto');
});

it('rejects a non-positive default volume per package', function (): void {
    expect(fn () => autoPlanParameters(volume: 0.0))
        ->toThrow(PlanningException::class, 'volumen por producto por defecto');
});

it('rejects a slack ratio outside [0, 1)', function (): void {
    expect(fn () => autoPlanParameters(slack: -0.1))
        ->toThrow(PlanningException::class, 'holgura de capacidad')
        ->and(fn () => autoPlanParameters(slack: 1.0))
        ->toThrow(PlanningException::class, 'holgura de capacidad');
});

it('rejects an empty departure time', function (): void {
    expect(fn () => autoPlanParameters(departAt: '   '))
        ->toThrow(PlanningException::class, 'hora de salida no puede estar vacía');
});
