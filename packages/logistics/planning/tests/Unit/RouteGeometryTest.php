<?php

declare(strict_types=1);

use Logistics\Planning\Domain\Exception\PlanningException;
use Logistics\Planning\Domain\ValueObject\RouteGeometry;

it('builds from valid coordinates and totals', function (): void {
    $geometry = RouteGeometry::of([[-68.15, -16.5], [-68.12, -16.49]], 1200.0, 300.0);

    expect($geometry->distanceMeters)->toBe(1200.0)
        ->and($geometry->durationSeconds)->toBe(300.0)
        ->and($geometry->coordinates)->toHaveCount(2);
});

it('rejects a geometry with fewer than two points', function (): void {
    expect(fn () => RouteGeometry::of([[-68.15, -16.5]], 10.0, 10.0))
        ->toThrow(PlanningException::class, 'al menos dos puntos');
});

it('rejects a negative total distance or duration', function (): void {
    expect(fn () => RouteGeometry::of([[0.0, 0.0], [1.0, 1.0]], -1.0, 10.0))
        ->toThrow(PlanningException::class, 'distancia total inválida')
        ->and(fn () => RouteGeometry::of([[0.0, 0.0], [1.0, 1.0]], 10.0, -1.0))
        ->toThrow(PlanningException::class, 'duración total inválida');
});

it('exposes a GeoJSON LineString primitive form', function (): void {
    $geometry = RouteGeometry::of([[-68.15, -16.5], [-68.12, -16.49]], 1200.0, 300.0);

    expect($geometry->toArray())->toBe([
        'type' => 'LineString',
        'coordinates' => [[-68.15, -16.5], [-68.12, -16.49]],
        'distance_meters' => 1200.0,
        'duration_seconds' => 300.0,
    ]);
});

it('reconstitutes from its persisted primitive form', function (): void {
    $geometry = RouteGeometry::fromArray([
        'coordinates' => [[-68.15, -16.5], [-68.12, -16.49]],
        'distance_meters' => 1200,
        'duration_seconds' => 300,
    ]);

    expect($geometry)->not->toBeNull()
        ->and($geometry?->distanceMeters)->toBe(1200.0)
        ->and($geometry?->coordinates[0])->toBe([-68.15, -16.5]);
});

it('returns null for malformed persisted data instead of throwing', function (): void {
    expect(RouteGeometry::fromArray(['coordinates' => 'nope', 'distance_meters' => 1, 'duration_seconds' => 1]))->toBeNull()
        ->and(RouteGeometry::fromArray(['coordinates' => [[0.0, 0.0]], 'distance_meters' => 1, 'duration_seconds' => 1]))->toBeNull()
        ->and(RouteGeometry::fromArray(['coordinates' => [['a', 'b'], [1, 2]], 'distance_meters' => 1, 'duration_seconds' => 1]))->toBeNull()
        ->and(RouteGeometry::fromArray(['coordinates' => [[0.0, 0.0], [1.0, 1.0]]]))->toBeNull();
});
