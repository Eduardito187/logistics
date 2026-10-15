<?php

declare(strict_types=1);

use Logistics\Planning\Domain\Service\RouteSequencer;
use Logistics\Planning\Domain\ValueObject\DistanceMatrix;

/**
 * Matriz de TIEMPOS 1D para el secuenciador: el índice 0 es el depósito (x=0) y las paradas 1..3 caen
 * en x = {10, 30, 20}. Visitarlas en el orden crudo 1-2-3 obliga a ir hasta x=30 y retroceder a x=20;
 * el orden 1-3-2 avanza monótono y cuesta menos.
 */
function timeMatrix(): DistanceMatrix
{
    $x = [0.0, 10.0, 30.0, 20.0];
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

/**
 * Costo (segundos) del camino abierto depósito (índice 0) → paradas en el orden dado, sin regreso.
 *
 * @param list<int> $order
 */
function pathCostOf(DistanceMatrix $matrix, array $order): float
{
    $cost = 0.0;
    $previous = 0;
    foreach ($order as $stop) {
        $cost += $matrix->durationSeconds($previous, $stop);
        $previous = $stop;
    }

    return $cost;
}

it('keeps a single stop as-is', function (): void {
    $sequencer = new RouteSequencer;

    expect($sequencer->sequence(timeMatrix(), [2]))->toBe([2]);
});

it('reorders to beat the raw delivery order', function (): void {
    $sequencer = new RouteSequencer;
    $matrix = timeMatrix();

    $raw = [1, 2, 3];
    $sequenced = $sequencer->sequence($matrix, $raw);

    // Es una permutación de las mismas paradas.
    $sortedIn = $raw;
    $sortedOut = $sequenced;
    sort($sortedIn);
    sort($sortedOut);
    expect($sortedOut)->toBe($sortedIn);

    // Y cuesta estrictamente menos que el orden crudo de entrada.
    expect(pathCostOf($matrix, $sequenced))->toBeLessThan(pathCostOf($matrix, $raw));

    // El óptimo de este caso es 1-3-2 (30 s frente a los 40 s del orden crudo).
    expect($sequenced)->toBe([1, 3, 2])
        ->and(pathCostOf($matrix, $sequenced))->toBe(30.0)
        ->and(pathCostOf($matrix, $raw))->toBe(40.0);
});

it('never produces a worse order than the input, whatever the input order', function (): void {
    $sequencer = new RouteSequencer;
    $matrix = timeMatrix();

    foreach ([[1, 2, 3], [3, 2, 1], [2, 1, 3], [2, 3, 1]] as $raw) {
        $sequenced = $sequencer->sequence($matrix, $raw);
        expect(pathCostOf($matrix, $sequenced))->toBeLessThanOrEqual(pathCostOf($matrix, $raw));
    }
});

it('is deterministic: the same stops always yield the same order', function (): void {
    $sequencer = new RouteSequencer;
    $matrix = timeMatrix();

    $first = $sequencer->sequence($matrix, [3, 1, 2]);
    $second = $sequencer->sequence($matrix, [3, 1, 2]);

    expect($second)->toBe($first);
});
