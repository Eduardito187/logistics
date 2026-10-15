<?php

declare(strict_types=1);

use Logistics\Telemetry\Domain\Exception\TelemetryException;
use Logistics\Telemetry\Domain\ValueObject\GeoPoint;

// VO GeoPoint: validación de rangos, distancia haversine y forma primitiva. PHP puro.

it('acepta coordenadas dentro de rango y expone su forma primitiva', function (): void {
    $point = GeoPoint::of(-16.5, -68.15);

    expect($point->lat)->toBe(-16.5)
        ->and($point->lng)->toBe(-68.15)
        ->and($point->toArray())->toBe(['lat' => -16.5, 'lng' => -68.15]);
});

it('rechaza latitud o longitud fuera de rango', function (callable $build): void {
    expect($build)->toThrow(TelemetryException::class);
})->with([
    'latitud > 90' => [fn () => GeoPoint::of(91.0, 0.0)],
    'latitud < -90' => [fn () => GeoPoint::of(-91.0, 0.0)],
    'longitud > 180' => [fn () => GeoPoint::of(0.0, 181.0)],
    'longitud < -180' => [fn () => GeoPoint::of(0.0, -181.0)],
]);

it('mide 0 metros contra sí mismo', function (): void {
    $point = GeoPoint::of(-17.78, -63.18);

    expect($point->distanceMetersTo($point))->toBe(0.0);
});

it('mide por haversine ~111 km por grado de longitud en el ecuador', function (): void {
    $a = GeoPoint::of(0.0, 0.0);
    $b = GeoPoint::of(0.0, 1.0);

    // Un grado de longitud en el ecuador ≈ 111.195 km.
    expect($a->distanceMetersTo($b))->toBeGreaterThan(111_000.0)
        ->and($a->distanceMetersTo($b))->toBeLessThan(111_400.0);
});

it('es simétrica y reconoce igualdad', function (): void {
    $a = GeoPoint::of(-16.5, -68.15);
    $b = GeoPoint::of(-16.6, -68.2);

    expect($a->distanceMetersTo($b))->toBe($b->distanceMetersTo($a))
        ->and($a->equals(GeoPoint::of(-16.5, -68.15)))->toBeTrue()
        ->and($a->equals($b))->toBeFalse();
});
