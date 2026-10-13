<?php

declare(strict_types=1);

use Logistics\Coverage\Domain\Exception\CoverageException;
use Logistics\Coverage\Domain\ValueObject\GeoPoint;

it('builds a point from valid coordinates', function (): void {
    $point = GeoPoint::of(-17.78, -63.18);

    expect($point->lat)->toBe(-17.78)
        ->and($point->lng)->toBe(-63.18)
        ->and($point->toGeoJsonCoordinates())->toBe([-63.18, -17.78]);
});

it('compares points by value', function (): void {
    expect(GeoPoint::of(-17.78, -63.18)->equals(GeoPoint::of(-17.78, -63.18)))->toBeTrue()
        ->and(GeoPoint::of(-17.78, -63.18)->equals(GeoPoint::of(0.0, 0.0)))->toBeFalse();
});

it('rejects coordinates out of range', function (callable $build): void {
    expect($build)->toThrow(CoverageException::class);
})->with([
    'latitude too high' => [fn () => GeoPoint::of(90.1, 0.0)],
    'latitude too low' => [fn () => GeoPoint::of(-90.1, 0.0)],
    'longitude too high' => [fn () => GeoPoint::of(0.0, 180.1)],
    'longitude too low' => [fn () => GeoPoint::of(0.0, -180.1)],
]);
