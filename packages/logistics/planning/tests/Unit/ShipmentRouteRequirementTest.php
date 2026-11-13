<?php

declare(strict_types=1);

use Logistics\Planning\Domain\Exception\PlanningException;
use Logistics\Planning\Domain\ValueObject\ShipmentRouteRequirement;

it('builds an empty requirement that demands nothing', function (): void {
    $requirement = ShipmentRouteRequirement::none();

    expect($requirement->isEmpty())->toBeTrue()
        ->and($requirement->loadType)->toBeNull()
        ->and($requirement->zone)->toBeNull()
        ->and($requirement->heightM)->toBeNull()
        ->and($requirement->weightKg)->toBeNull();
});

it('cleans blank strings down to null', function (): void {
    $requirement = ShipmentRouteRequirement::of('  fragile  ', '   ', 1.8, 25.0);

    expect($requirement->loadType)->toBe('fragile')
        ->and($requirement->zone)->toBeNull()
        ->and($requirement->heightM)->toBe(1.8)
        ->and($requirement->weightKg)->toBe(25.0)
        ->and($requirement->isEmpty())->toBeFalse();
});

it('rejects a negative height or weight', function (): void {
    expect(fn () => ShipmentRouteRequirement::of(null, null, -0.1, null))
        ->toThrow(PlanningException::class, 'altura del producto no puede ser negativa')
        ->and(fn () => ShipmentRouteRequirement::of(null, null, null, -1.0))
        ->toThrow(PlanningException::class, 'peso del producto no puede ser negativo');
});

it('reconstitutes from a persisted primitive form, tolerating missing or wrong-typed data', function (): void {
    $requirement = ShipmentRouteRequirement::fromArray([
        'load_type' => 'bulky',
        'zone' => 'north',
        'height_m' => '2.1',
        'weight_kg' => 40,
    ]);

    expect($requirement->loadType)->toBe('bulky')
        ->and($requirement->zone)->toBe('north')
        ->and($requirement->heightM)->toBe(2.1)
        ->and($requirement->weightKg)->toBe(40.0);

    $empty = ShipmentRouteRequirement::fromArray(['load_type' => 123, 'height_m' => 'x']);
    expect($empty->isEmpty())->toBeTrue();
});

it('exposes a primitive form for persistence', function (): void {
    $requirement = ShipmentRouteRequirement::of('fragile', 'north', 1.8, 25.0);

    expect($requirement->toArray())->toBe([
        'load_type' => 'fragile',
        'zone' => 'north',
        'height_m' => 1.8,
        'weight_kg' => 25.0,
    ]);
});
