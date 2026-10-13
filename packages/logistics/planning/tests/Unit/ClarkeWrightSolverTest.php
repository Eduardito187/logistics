<?php

declare(strict_types=1);

use Logistics\Planning\Domain\Service\ClarkeWrightSolver;
use Logistics\Planning\Domain\ValueObject\DistanceMatrix;

/**
 * Matriz 1D de prueba: el índice 0 es el depósito (x=0) y las paradas 1..4 caen sobre una recta en
 * x = {100, 101, 200, 201}. La distancia (y el tiempo, que el solver no usa) es la diferencia absoluta.
 * Así las paradas 1-2 quedan muy juntas y lejos del depósito (ahorro grande al servirlas juntas), lo
 * mismo que 3-4, mientras que los dos grupos están separados entre sí.
 */
function lineMatrix(): DistanceMatrix
{
    $x = [0.0, 100.0, 101.0, 200.0, 201.0];
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
 * Aplana las rutas a la lista ordenada de todas las paradas, para comprobar cobertura total.
 *
 * @param  list<list<int>> $routes
 * @return list<int>
 */
function flattenStops(array $routes): array
{
    $all = [];
    foreach ($routes as $route) {
        foreach ($route as $stop) {
            $all[] = $stop;
        }
    }
    sort($all);

    return array_values($all);
}

it('returns no routes when the matrix only has the depot', function (): void {
    $solver = new ClarkeWrightSolver;
    $matrix = DistanceMatrix::fromArrays([[0.0]], [[0.0]]);

    expect($solver->solve($matrix, [], [], 100.0, 100.0))->toBe([]);
});

it('merges every stop into a single route when capacity is unlimited', function (): void {
    $solver = new ClarkeWrightSolver;
    $demandsKg = [1 => 1.0, 2 => 1.0, 3 => 1.0, 4 => 1.0];
    $demandsM3 = [1 => 0.1, 2 => 0.1, 3 => 0.1, 4 => 0.1];

    $routes = $solver->solve(lineMatrix(), $demandsKg, $demandsM3, 1000.0, 1000.0);

    expect($routes)->toHaveCount(1)
        ->and(flattenStops($routes))->toBe([1, 2, 3, 4]);
});

it('respects the kg capacity, splitting into routes that never exceed it', function (): void {
    $solver = new ClarkeWrightSolver;
    // Cada parada pesa 1 kg y la capacidad utilizable es 2 kg: a lo sumo dos paradas por camión.
    $demandsKg = [1 => 1.0, 2 => 1.0, 3 => 1.0, 4 => 1.0];
    $demandsM3 = [1 => 0.1, 2 => 0.1, 3 => 0.1, 4 => 0.1];

    $routes = $solver->solve(lineMatrix(), $demandsKg, $demandsM3, 2.0, 10.0);

    // Toda parada aparece exactamente una vez (cobertura total, sin duplicados).
    expect(flattenStops($routes))->toBe([1, 2, 3, 4]);

    // Ninguna ruta excede la capacidad en kg ni en m3.
    foreach ($routes as $route) {
        expect(count($route))->toBeLessThanOrEqual(2);
        $kg = array_sum(array_map(static fn (int $s): float => $demandsKg[$s], $route));
        expect($kg)->toBeLessThanOrEqual(2.0);
    }

    // El ahorro agrupa las paradas cercanas: {1,2} por un lado y {3,4} por otro.
    $sets = array_map(static function (array $route): array {
        sort($route);

        return $route;
    }, $routes);
    sort($sets);
    expect($sets)->toBe([[1, 2], [3, 4]]);
});

it('respects the m3 capacity even when kg is unlimited', function (): void {
    $solver = new ClarkeWrightSolver;
    $demandsKg = [1 => 0.1, 2 => 0.1, 3 => 0.1, 4 => 0.1];
    // Volumen que solo deja entrar dos paradas por camión (0.4 + 0.4 = 0.8 <= 1.0; una tercera no cabe).
    $demandsM3 = [1 => 0.4, 2 => 0.4, 3 => 0.4, 4 => 0.4];

    $routes = $solver->solve(lineMatrix(), $demandsKg, $demandsM3, 1000.0, 1.0);

    expect(flattenStops($routes))->toBe([1, 2, 3, 4]);
    foreach ($routes as $route) {
        $m3 = array_sum(array_map(static fn (int $s): float => $demandsM3[$s], $route));
        expect($m3)->toBeLessThanOrEqual(1.0);
    }
});

it('is deterministic: the same input always yields the same partition', function (): void {
    $solver = new ClarkeWrightSolver;
    $demandsKg = [1 => 1.0, 2 => 1.0, 3 => 1.0, 4 => 1.0];
    $demandsM3 = [1 => 0.1, 2 => 0.1, 3 => 0.1, 4 => 0.1];

    $first = $solver->solve(lineMatrix(), $demandsKg, $demandsM3, 2.0, 10.0);
    $second = $solver->solve(lineMatrix(), $demandsKg, $demandsM3, 2.0, 10.0);

    expect($second)->toBe($first);
});
