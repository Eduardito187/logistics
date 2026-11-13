<?php

declare(strict_types=1);

use Logistics\Planning\Domain\Exception\PlanningException;
use Logistics\Planning\Domain\ValueObject\PublishThresholds;

it('builds valid thresholds', function (): void {
    $thresholds = PublishThresholds::of(1.0, 0.3, true);

    expect($thresholds->maxOccupancy)->toBe(1.0)
        ->and($thresholds->minOccupancy)->toBe(0.3)
        ->and($thresholds->requireVehicleAvailable)->toBeTrue();
});

it('allows disabling both occupancy checks with zero', function (): void {
    $thresholds = PublishThresholds::of(0.0, 0.0, false);

    expect($thresholds->maxOccupancy)->toBe(0.0)
        ->and($thresholds->minOccupancy)->toBe(0.0);
});

it('rejects a negative minimum occupancy', function (): void {
    expect(fn () => PublishThresholds::of(1.0, -0.1, false))
        ->toThrow(PlanningException::class, 'ocupación mínima no puede ser negativa');
});

it('rejects a minimum occupancy above the maximum when both are active', function (): void {
    expect(fn () => PublishThresholds::of(0.5, 0.8, false))
        ->toThrow(PlanningException::class, 'no puede superar a la máxima');
});
