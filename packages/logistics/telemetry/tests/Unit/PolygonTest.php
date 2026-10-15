<?php

declare(strict_types=1);

use Logistics\Telemetry\Domain\Exception\TelemetryException;
use Logistics\Telemetry\Domain\ValueObject\GeoPoint;
use Logistics\Telemetry\Domain\ValueObject\Polygon;

// VO Polygon: anillo cerrado y prueba de pertenencia por ray casting. PHP puro.

/**
 * Cuadrado de lado 2 centrado en el origen, como lista de pares {lat,lng}.
 *
 * @return list<array{lat: float, lng: float}>
 */
function cuadrado(): array
{
    return [
        ['lat' => -1.0, 'lng' => -1.0],
        ['lat' => -1.0, 'lng' => 1.0],
        ['lat' => 1.0, 'lng' => 1.0],
        ['lat' => 1.0, 'lng' => -1.0],
    ];
}

it('exige al menos 3 puntos', function (): void {
    expect(fn () => Polygon::fromArray([
        ['lat' => 0.0, 'lng' => 0.0],
        ['lat' => 1.0, 'lng' => 1.0],
    ]))->toThrow(TelemetryException::class);
});

it('cierra el anillo repitiendo el primer punto', function (): void {
    $polygon = Polygon::fromArray(cuadrado());

    // 4 vértices distintos + el cierre = 5.
    expect($polygon->vertices)->toHaveCount(5)
        ->and($polygon->vertices[0]->equals($polygon->vertices[4]))->toBeTrue();
});

it('reconoce un punto dentro del polígono', function (): void {
    $polygon = Polygon::fromArray(cuadrado());

    expect($polygon->contains(GeoPoint::of(0.0, 0.0)))->toBeTrue()
        ->and($polygon->contains(GeoPoint::of(0.5, -0.5)))->toBeTrue();
});

it('reconoce un punto fuera del polígono', function (): void {
    $polygon = Polygon::fromArray(cuadrado());

    expect($polygon->contains(GeoPoint::of(2.0, 2.0)))->toBeFalse()
        ->and($polygon->contains(GeoPoint::of(0.0, 5.0)))->toBeFalse();
});
