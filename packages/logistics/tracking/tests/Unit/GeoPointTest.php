<?php

declare(strict_types=1);

use Logistics\Tracking\Domain\Exception\TrackingException;
use Logistics\Tracking\Domain\ValueObject\GeoPoint;

// Punto geográfico WGS 84: rangos validados, comparación por valor y forma primitiva.

it('construye un punto dentro de rango', function (): void {
    $point = GeoPoint::of(-16.5, -68.15);

    expect($point->latitude)->toBe(-16.5)
        ->and($point->longitude)->toBe(-68.15);
});

it('rechaza una latitud fuera de rango', function (): void {
    expect(fn () => GeoPoint::of(-91.0, 0.0))
        ->toThrow(TrackingException::class, 'latitud');
});

it('rechaza una longitud fuera de rango', function (): void {
    expect(fn () => GeoPoint::of(0.0, 181.0))
        ->toThrow(TrackingException::class, 'longitud');
});

it('compara por coordenadas', function (): void {
    $point = GeoPoint::of(-16.5, -68.15);

    expect($point->equals(GeoPoint::of(-16.5, -68.15)))->toBeTrue()
        ->and($point->equals(GeoPoint::of(-16.4, -68.15)))->toBeFalse();
});

it('expone su forma primitiva lat/lng', function (): void {
    expect(GeoPoint::of(-16.5, -68.15)->toArray())->toBe(['lat' => -16.5, 'lng' => -68.15]);
});
