<?php

declare(strict_types=1);

use Logistics\Coverage\Domain\Exception\CoverageException;
use Logistics\Coverage\Domain\ValueObject\Polygon;

/**
 * Anillo cuadrado cerrado válido (orden GeoJSON [lng, lat]).
 *
 * @return array<int, array{0: float, 1: float}>
 */
function validRing(): array
{
    return [
        [-63.2, -17.8],
        [-63.1, -17.8],
        [-63.1, -17.7],
        [-63.2, -17.7],
        [-63.2, -17.8],
    ];
}

it('builds a polygon from a closed ring', function (): void {
    $polygon = Polygon::fromRing(validRing());

    expect($polygon->ring)->toHaveCount(5)
        ->and($polygon->ring[0])->toBe([-63.2, -17.8]);
});

it('exposes a GeoJSON Polygon geometry', function (): void {
    $geojson = Polygon::fromRing(validRing())->toGeoJson();

    expect($geojson['type'])->toBe('Polygon')
        ->and($geojson['coordinates'])->toBe([validRing()]);
});

it('normalizes integer coordinates to float', function (): void {
    $polygon = Polygon::fromRing([[0, 0], [1, 0], [1, 1], [0, 0]]);

    expect($polygon->ring[0])->toBe([0.0, 0.0]);
});

it('rejects a ring with fewer than four points', function (): void {
    expect(fn () => Polygon::fromRing([[0.0, 0.0], [1.0, 0.0], [0.0, 0.0]]))
        ->toThrow(CoverageException::class, 'al menos 4 puntos');
});

it('rejects a ring that is not closed', function (): void {
    expect(fn () => Polygon::fromRing([
        [-63.2, -17.8],
        [-63.1, -17.8],
        [-63.1, -17.7],
        [-63.2, -17.7],
    ]))->toThrow(CoverageException::class, 'no está cerrado');
});
