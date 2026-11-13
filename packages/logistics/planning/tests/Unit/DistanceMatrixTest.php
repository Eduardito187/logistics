<?php

declare(strict_types=1);

use Logistics\Planning\Domain\Exception\PlanningException;
use Logistics\Planning\Domain\ValueObject\DistanceMatrix;

it('builds a square matrix and reads its cells', function (): void {
    $distances = [[0.0, 10.0], [10.0, 0.0]];
    $durations = [[0.0, 60.0], [60.0, 0.0]];

    $matrix = DistanceMatrix::fromArrays($distances, $durations);

    expect($matrix->size())->toBe(2)
        ->and($matrix->distanceMeters(0, 1))->toBe(10.0)
        ->and($matrix->durationSeconds(1, 0))->toBe(60.0)
        ->and($matrix->distanceMeters(0, 0))->toBe(0.0);
});

it('rejects an empty matrix', function (): void {
    expect(fn () => DistanceMatrix::fromArrays([], []))
        ->toThrow(PlanningException::class, 'no puede estar vacía');
});

it('rejects distances and durations of different row counts', function (): void {
    expect(fn () => DistanceMatrix::fromArrays([[0.0, 1.0], [1.0, 0.0]], [[0.0, 1.0]]))
        ->toThrow(PlanningException::class, 'misma cantidad de filas');
});

it('rejects a non-square matrix', function (): void {
    expect(fn () => DistanceMatrix::fromArrays([[0.0, 1.0], [1.0]], [[0.0, 1.0], [1.0, 0.0]]))
        ->toThrow(PlanningException::class, 'debe ser cuadrada');
});

it('rejects reading a cell out of range', function (): void {
    $matrix = DistanceMatrix::fromArrays([[0.0]], [[0.0]]);

    expect(fn () => $matrix->distanceMeters(0, 5))
        ->toThrow(PlanningException::class, 'índice fuera de rango');
});
