<?php

declare(strict_types=1);

use Logistics\Planning\Domain\Service\RouteSequencer;
use Logistics\Planning\Domain\ValueObject\DistanceMatrix;
use Logistics\Planning\Domain\ValueObject\TimeWindow;

/**
 * Matriz 1D para el secuenciador con ventanas: índice 0 = depósito (x=0) y paradas 1..3 en x = {10, 20, 30}.
 * El tiempo entre dos puntos es la diferencia absoluta de x (segundos). Por geometría pura, el mejor orden
 * de viaje es el monótono 1-2-3 (30 s); cualquier ventana que lo altere lo hace como RESTRICCIÓN, no por
 * distancia.
 */
function lineTimeMatrix(): DistanceMatrix
{
    $x = [0.0, 10.0, 20.0, 30.0];
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
 * Las mismas paradas, ordenadas, para comprobar cobertura total (una permutación, sin perder ni duplicar).
 *
 * @param  list<int> $stops
 * @return list<int>
 */
function sortedStops(array $stops): array
{
    sort($stops);

    return array_values($stops);
}

/**
 * Suma del retraso (segundos) de un orden dado sus ventanas: 0 significa que NINGUNA parada se agenda
 * después de su cierre. Replica el reloj con espera del solver para verificar el resultado desde afuera.
 *
 * @param list<int>              $order
 * @param array<int, TimeWindow> $windows
 */
function totalLatenessOf(DistanceMatrix $matrix, array $order, array $windows): float
{
    $lateness = 0.0;
    $clock = 0.0;
    $previous = 0;
    foreach ($order as $stop) {
        $arrival = $clock + $matrix->durationSeconds($previous, $stop);
        $window = $windows[$stop] ?? null;
        if ($window !== null) {
            $arrival = $window->effectiveArrivalFrom($arrival);
            $lateness += $window->latenessAt($arrival);
        }
        $clock = $arrival;
        $previous = $stop;
    }

    return $lateness;
}

it('keeps the travel-optimal order when there are no windows', function (): void {
    $sequencer = new RouteSequencer;

    // Sin ventanas, el comportamiento es el de siempre: orden monótono 1-2-3.
    expect($sequencer->sequence(lineTimeMatrix(), [1, 2, 3]))->toBe([1, 2, 3])
        ->and($sequencer->sequence(lineTimeMatrix(), [1, 2, 3], []))->toBe([1, 2, 3]);
});

it('reorders against travel to respect the delivery windows', function (): void {
    $sequencer = new RouteSequencer;
    $matrix = lineTimeMatrix();

    // La parada 3 cierra temprano (0..60 s); las paradas 2 y 1 abren tarde (100 s y 200 s). El orden
    // monótono 1-2-3 llegaría a la 3 recién a los 220 s (incumple); el orden 3-2-1 respeta todo.
    $windows = [
        1 => TimeWindow::of(200.0, null),
        2 => TimeWindow::of(100.0, null),
        3 => TimeWindow::of(0.0, 60.0),
    ];

    $sequenced = $sequencer->sequence($matrix, [1, 2, 3], $windows);

    expect($sequenced)->toBe([3, 2, 1])
        // Es una permutación de las mismas paradas (cobertura total).
        ->and(sortedStops($sequenced))->toBe([1, 2, 3])
        // Ninguna parada queda agendada después de su cierre.
        ->and(totalLatenessOf($matrix, $sequenced, $windows))->toBe(0.0)
        // Y mejora estrictamente el retraso frente al orden crudo por viaje.
        ->and(totalLatenessOf($matrix, [1, 2, 3], $windows))->toBeGreaterThan(0.0);
});

it('does not schedule a stop before its window opens (it waits)', function (): void {
    $sequencer = new RouteSequencer;
    $matrix = lineTimeMatrix();

    $windows = [
        1 => TimeWindow::of(200.0, null),
        2 => TimeWindow::of(100.0, null),
        3 => TimeWindow::of(0.0, 60.0),
    ];

    $order = $sequencer->sequence($matrix, [1, 2, 3], $windows);
    $arrivals = $sequencer->arrivalSeconds($matrix, $order, $windows);

    // Orden 3-2-1: llega a 3 a los 30 s (abre a 0), espera hasta 100 para 2 y hasta 200 para 1.
    expect($order)->toBe([3, 2, 1])
        ->and($arrivals)->toBe([30.0, 100.0, 200.0]);
});

it('computes plain cumulative travel arrivals when there are no windows', function (): void {
    $sequencer = new RouteSequencer;
    $matrix = lineTimeMatrix();

    // Sin ventanas no hay espera: el arribo es el tiempo de viaje acumulado desde la salida.
    expect($sequencer->arrivalSeconds($matrix, [1, 2, 3]))->toBe([10.0, 20.0, 30.0]);
});

it('honors a window only for the stops that have one (mixed route)', function (): void {
    $sequencer = new RouteSequencer;
    $matrix = lineTimeMatrix();

    // Solo la parada 3 trae ventana (cierra a 35 s); 1 y 2 quedan libres. El solver respeta la de la 3.
    $windows = [3 => TimeWindow::of(0.0, 35.0)];

    $sequenced = $sequencer->sequence($matrix, [1, 2, 3], $windows);

    expect(sortedStops($sequenced))->toBe([1, 2, 3])
        ->and(totalLatenessOf($matrix, $sequenced, $windows))->toBe(0.0);
});

it('is deterministic with windows: same input yields the same order', function (): void {
    $sequencer = new RouteSequencer;
    $matrix = lineTimeMatrix();
    $windows = [
        1 => TimeWindow::of(200.0, null),
        2 => TimeWindow::of(100.0, null),
        3 => TimeWindow::of(0.0, 60.0),
    ];

    $first = $sequencer->sequence($matrix, [2, 3, 1], $windows);
    $second = $sequencer->sequence($matrix, [2, 3, 1], $windows);

    expect($second)->toBe($first);
});

it('never drops or duplicates a stop, even with an unreachable window', function (): void {
    $sequencer = new RouteSequencer;
    $matrix = lineTimeMatrix();

    // La parada 3 está, como mínimo, a 30 s del depósito pero su ventana cierra a los 20 s: inalcanzable.
    // El solver no se cae ni pierde paradas; entrega igual una secuencia completa.
    $windows = [3 => TimeWindow::of(0.0, 20.0)];

    $sequenced = $sequencer->sequence($matrix, [1, 2, 3], $windows);

    expect(sortedStops($sequenced))->toBe([1, 2, 3]);
});
