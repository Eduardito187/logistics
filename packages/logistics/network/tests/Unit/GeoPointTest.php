<?php

declare(strict_types=1);

use Logistics\Network\Domain\Exception\NetworkException;
use Logistics\Network\Domain\ValueObject\GeoPoint;

it('accepts coordinates within range', function (): void {
    $point = GeoPoint::of(-16.5, -68.15);

    expect($point->latitude)->toBe(-16.5)
        ->and($point->longitude)->toBe(-68.15)
        ->and($point->toArray())->toBe(['lat' => -16.5, 'lng' => -68.15]);
});

it('accepts the boundary values', function (): void {
    expect(GeoPoint::of(90.0, 180.0)->toArray())->toBe(['lat' => 90.0, 'lng' => 180.0])
        ->and(GeoPoint::of(-90.0, -180.0)->toArray())->toBe(['lat' => -90.0, 'lng' => -180.0]);
});

it('compares two points by value', function (): void {
    expect(GeoPoint::of(1.0, 2.0)->equals(GeoPoint::of(1.0, 2.0)))->toBeTrue()
        ->and(GeoPoint::of(1.0, 2.0)->equals(GeoPoint::of(1.0, 3.0)))->toBeFalse();
});

it('rejects out-of-range coordinates', function (callable $build, string $reason): void {
    expect($build)->toThrow(NetworkException::class, $reason);
})->with([
    'latitude too high' => [fn () => GeoPoint::of(90.1, 0.0), 'latitud'],
    'latitude too low' => [fn () => GeoPoint::of(-90.1, 0.0), 'latitud'],
    'longitude too high' => [fn () => GeoPoint::of(0.0, 180.1), 'longitud'],
    'longitude too low' => [fn () => GeoPoint::of(0.0, -180.1), 'longitud'],
]);
