<?php

declare(strict_types=1);

use Logistics\Planning\Domain\Exception\PlanningException;
use Logistics\Planning\Domain\ValueObject\GeoPoint;

it('builds from a valid latitude and longitude', function (): void {
    $point = GeoPoint::of(-16.5, -68.15);

    expect($point->latitude)->toBe(-16.5)
        ->and($point->longitude)->toBe(-68.15);
});

it('rejects a latitude out of range', function (): void {
    expect(fn () => GeoPoint::of(-91.0, 0.0))
        ->toThrow(PlanningException::class, 'latitud fuera de rango')
        ->and(fn () => GeoPoint::of(91.0, 0.0))
        ->toThrow(PlanningException::class, 'latitud fuera de rango');
});

it('rejects a longitude out of range', function (): void {
    expect(fn () => GeoPoint::of(0.0, -181.0))
        ->toThrow(PlanningException::class, 'longitud fuera de rango')
        ->and(fn () => GeoPoint::of(0.0, 181.0))
        ->toThrow(PlanningException::class, 'longitud fuera de rango');
});

it('builds a stable cache key rounded to six decimals', function (): void {
    expect(GeoPoint::of(-16.5, -68.15)->cacheKey())->toBe('-16.500000,-68.150000');
});

it('measures the haversine distance between two points in meters', function (): void {
    $a = GeoPoint::of(-16.5, -68.15);
    $b = GeoPoint::of(-16.5, -68.15);

    // Mismo punto: distancia cero.
    expect($a->haversineMeters($b))->toEqualWithDelta(0.0, 1e-6);

    // Un grado de longitud sobre el ecuador ~ 111.19 km.
    $equatorA = GeoPoint::of(0.0, 0.0);
    $equatorB = GeoPoint::of(0.0, 1.0);
    expect($equatorA->haversineMeters($equatorB))->toEqualWithDelta(111_194.0, 200.0);
});
