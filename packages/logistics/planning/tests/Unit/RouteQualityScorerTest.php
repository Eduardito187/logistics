<?php

declare(strict_types=1);

use Logistics\Planning\Domain\Service\RouteQualityScorer;
use Logistics\Planning\Domain\ValueObject\DistanceMatrix;

/**
 * Métricas de calidad de ruta (R-06): distancia/tiempo del recorrido y ocupación del camión (carga vs
 * capacidad). Cálculo puro y determinista.
 *
 * Matriz 1D: depósito en x=0 y paradas 1..3 en x={10,25,40}; distancia/tiempo = diferencia absoluta.
 */
function qualityLineMatrix(): DistanceMatrix
{
    $x = [0.0, 10.0, 25.0, 40.0];
    $grid = [];
    foreach ($x as $from) {
        $row = [];
        foreach ($x as $to) {
            $row[] = abs($from - $to);
        }
        $grid[] = $row;
    }

    return DistanceMatrix::fromArrays($grid, $grid);
}

it('sums the open-path legs from the depot in delivery order', function (): void {
    $scorer = new RouteQualityScorer;

    // Depósito(0) → 1 → 2 → 3 = 10 + 15 + 15 = 40 (sin regreso al depósito).
    [$distance, $duration] = $scorer->legMetrics(qualityLineMatrix(), [1, 2, 3]);

    expect($distance)->toBe(40.0)
        ->and($duration)->toBe(40.0);
});

it('computes occupancy as load over capacity on each axis', function (): void {
    $scorer = new RouteQualityScorer;

    $score = $scorer->score(1000.0, 3600.0, 850.0, 9.0, 1000.0, 10.0);

    expect($score->occupancyKg)->toBe(0.85)
        ->and($score->occupancyM3)->toBe(0.9)
        ->and($score->distanceMeters)->toBe(1000.0)
        ->and($score->durationSeconds)->toBe(3600.0);
});

it('leaves occupancy null when the capacity is unknown or not positive', function (): void {
    $scorer = new RouteQualityScorer;

    $score = $scorer->score(500.0, 1800.0, 400.0, 4.0, null, 0.0);

    expect($score->occupancyKg)->toBeNull()
        ->and($score->occupancyM3)->toBeNull();
});

it('exposes an over-capacity occupancy above 1 without clamping', function (): void {
    $scorer = new RouteQualityScorer;

    // Camión sobrecargado: 1200 kg en 1000 kg de capacidad → 1.2 (se expone tal cual para marcarlo).
    $score = $scorer->score(0.0, 0.0, 1200.0, 5.0, 1000.0, 10.0);

    expect($score->occupancyKg)->toBe(1.2);
});

it('serializes to a primitive array for caching and APIs', function (): void {
    $scorer = new RouteQualityScorer;

    $array = $scorer->score(1000.0, 3600.0, 500.0, 5.0, 1000.0, 10.0)->toArray();

    expect($array)->toMatchArray([
        'distance_meters' => 1000.0,
        'duration_seconds' => 3600.0,
        'load_kg' => 500.0,
        'load_m3' => 5.0,
        'capacity_kg' => 1000.0,
        'capacity_m3' => 10.0,
        'occupancy_kg' => 0.5,
        'occupancy_m3' => 0.5,
    ]);
});
