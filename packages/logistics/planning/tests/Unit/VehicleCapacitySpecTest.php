<?php

declare(strict_types=1);

use Logistics\Planning\Domain\Exception\PlanningException;
use Logistics\Planning\Domain\ValueObject\VehicleCapacitySpec;
use Logistics\Planning\Domain\ValueObject\VehicleRouteProfile;

it('builds a spec, trimming ids and defaulting to an unrestricted profile', function (): void {
    $spec = VehicleCapacitySpec::of('  veh-1  ', 1000.0, 12.0, '  node-1  ', true);

    expect($spec->vehicleId)->toBe('veh-1')
        ->and($spec->capacityKg)->toBe(1000.0)
        ->and($spec->capacityM3)->toBe(12.0)
        ->and($spec->homeBaseNodeId)->toBe('node-1')
        ->and($spec->hasCrew)->toBeTrue()
        ->and($spec->departmentId)->toBeNull()
        ->and($spec->profile)->toBeInstanceOf(VehicleRouteProfile::class);
});

it('rejects an empty vehicle id', function (): void {
    expect(fn () => VehicleCapacitySpec::of('   ', 1000.0, 12.0, 'node-1', true))
        ->toThrow(PlanningException::class, 'necesita un id');
});

it('rejects non-positive capacities', function (): void {
    expect(fn () => VehicleCapacitySpec::of('veh-1', 0.0, 12.0, 'node-1', true))
        ->toThrow(PlanningException::class, 'capacidad en kg inválida')
        ->and(fn () => VehicleCapacitySpec::of('veh-1', 1000.0, 0.0, 'node-1', true))
        ->toThrow(PlanningException::class, 'capacidad en m3 inválida');
});

it('resolves the home base point only when both coordinates are present', function (): void {
    $withCoords = VehicleCapacitySpec::of('veh-1', 1000.0, 12.0, 'node-1', true, 'LP', -16.5, -68.15);
    $withoutCoords = VehicleCapacitySpec::of('veh-1', 1000.0, 12.0, 'node-1', true, 'LP', null, -68.15);

    expect($withCoords->homeBasePoint())->not->toBeNull()
        ->and($withCoords->homeBasePoint()?->latitude)->toBe(-16.5)
        ->and($withoutCoords->homeBasePoint())->toBeNull();
});
