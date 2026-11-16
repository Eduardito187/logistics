<?php

declare(strict_types=1);

use Logistics\Tracking\Domain\Exception\TrackingException;
use Logistics\Tracking\Domain\ValueObject\GeoPoint;
use Logistics\Tracking\Domain\ValueObject\TruckPosition;

// Cinemática GPS de la posición del camión: campos opcionales (velocidad, rumbo, precisión) y su derivación
// a partir de dos posiciones seguidas cuando el dispositivo no los manda. Dato puro del dominio, sin I/O.

const TPK_LA_PAZ_LAT = -16.5000;

const TPK_LA_PAZ_LNG = -68.1500;

function tpkAt(float $lat, float $lng, string $iso, ?float $speed = null, ?float $heading = null, ?float $accuracy = null): TruckPosition
{
    return TruckPosition::at(GeoPoint::of($lat, $lng), new DateTimeImmutable($iso), $speed, $heading, $accuracy);
}

it('conserva la cinemática que vino en el reporte', function (): void {
    $position = tpkAt(TPK_LA_PAZ_LAT, TPK_LA_PAZ_LNG, '2026-11-16T10:00:00+00:00', 12.5, 90.0, 8.0);

    expect($position->speedMps)->toBe(12.5)
        ->and($position->headingDeg)->toBe(90.0)
        ->and($position->accuracyM)->toBe(8.0);
});

it('rechaza velocidad negativa, rumbo fuera de 0..360 y precisión negativa', function (): void {
    expect(fn () => tpkAt(TPK_LA_PAZ_LAT, TPK_LA_PAZ_LNG, '2026-11-16T10:00:00+00:00', -1.0))
        ->toThrow(TrackingException::class);
    expect(fn () => tpkAt(TPK_LA_PAZ_LAT, TPK_LA_PAZ_LNG, '2026-11-16T10:00:00+00:00', null, 361.0))
        ->toThrow(TrackingException::class);
    expect(fn () => tpkAt(TPK_LA_PAZ_LAT, TPK_LA_PAZ_LNG, '2026-11-16T10:00:00+00:00', null, null, -0.5))
        ->toThrow(TrackingException::class);
});

it('deriva velocidad (m/s) y rumbo de dos posiciones seguidas cuando no vinieron', function (): void {
    // Un punto ~1 km al este del anterior, 100 s después: velocidad ≈ 10 m/s, rumbo ≈ 90° (este).
    $previous = tpkAt(TPK_LA_PAZ_LAT, TPK_LA_PAZ_LNG, '2026-11-16T10:00:00+00:00');
    $current = tpkAt(TPK_LA_PAZ_LAT, -68.1406, '2026-11-16T10:01:40+00:00');

    $derived = $current->deriveKinematicsFrom($previous);

    expect($derived->speedMps)->toBeGreaterThan(8.0)->toBeLessThan(12.0)
        ->and($derived->headingDeg)->toBeGreaterThan(80.0)->toBeLessThan(100.0);
});

it('no pisa la velocidad que sí vino al derivar', function (): void {
    $previous = tpkAt(TPK_LA_PAZ_LAT, TPK_LA_PAZ_LNG, '2026-11-16T10:00:00+00:00');
    $current = tpkAt(TPK_LA_PAZ_LAT, -68.1406, '2026-11-16T10:01:40+00:00', 3.0);

    expect($current->deriveKinematicsFrom($previous)->speedMps)->toBe(3.0);
});

it('sin posición anterior, o sin avance de tiempo, no deriva nada', function (): void {
    $current = tpkAt(TPK_LA_PAZ_LAT, -68.1406, '2026-11-16T10:01:40+00:00');

    expect($current->deriveKinematicsFrom(null)->speedMps)->toBeNull();

    $same = tpkAt(TPK_LA_PAZ_LAT, TPK_LA_PAZ_LNG, '2026-11-16T10:01:40+00:00');
    expect($current->deriveKinematicsFrom($same)->speedMps)->toBeNull();
});
