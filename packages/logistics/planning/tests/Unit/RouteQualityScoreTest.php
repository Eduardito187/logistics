<?php

declare(strict_types=1);

use Logistics\Planning\Domain\Exception\PlanningException;
use Logistics\Planning\Domain\ValueObject\RouteQualityScore;

it('computes the occupancy ratio when capacities are known and positive', function (): void {
    $score = RouteQualityScore::of(10_000.0, 1_800.0, 850.0, 9.0, 1_000.0, 12.0);

    expect($score->occupancyKg)->toEqualWithDelta(0.85, 1e-9)
        ->and($score->occupancyM3)->toEqualWithDelta(0.75, 1e-9);
});

it('leaves the occupancy null when the capacity is unknown or not positive', function (): void {
    $score = RouteQualityScore::of(10_000.0, 1_800.0, 850.0, 9.0, null, 0.0);

    expect($score->occupancyKg)->toBeNull()
        ->and($score->occupancyM3)->toBeNull();
});

it('exposes an overloaded occupancy above one without clamping', function (): void {
    $score = RouteQualityScore::of(1.0, 1.0, 1_200.0, 1.0, 1_000.0);

    expect($score->occupancyKg)->toEqualWithDelta(1.2, 1e-9);
});

it('rejects a negative distance, duration or load', function (): void {
    expect(fn () => RouteQualityScore::of(-1.0, 1.0, 0.0, 0.0))
        ->toThrow(PlanningException::class, 'distancia total inválida')
        ->and(fn () => RouteQualityScore::of(1.0, -1.0, 0.0, 0.0))
        ->toThrow(PlanningException::class, 'duración total inválida')
        ->and(fn () => RouteQualityScore::of(1.0, 1.0, -1.0, 0.0))
        ->toThrow(PlanningException::class, 'carga de la ruta no puede ser negativa');
});

it('exposes a primitive form for persistence', function (): void {
    $score = RouteQualityScore::of(10_000.0, 1_800.0, 850.0, 9.0, 1_000.0, 12.0);

    expect($score->toArray())->toMatchArray([
        'distance_meters' => 10_000.0,
        'duration_seconds' => 1_800.0,
        'load_kg' => 850.0,
        'load_m3' => 9.0,
        'capacity_kg' => 1_000.0,
        'capacity_m3' => 12.0,
    ]);
});
