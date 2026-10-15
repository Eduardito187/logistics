<?php

declare(strict_types=1);

namespace Logistics\Planning\Domain\Service;

use Logistics\Planning\Domain\ValueObject\DistanceMatrix;

/**
 * Algoritmo de AHORROS de Clarke-Wright (Clarke & Wright, 1964) para el problema de ruteo de vehículos
 * (VRP) con restricción de capacidad. PHP puro, determinista y sin I/O: trabaja sobre una matriz de
 * distancias por carretera ya calculada.
 *
 * Convención de la matriz: el índice 0 es el DEPÓSITO (CD) y los índices 1..n son las paradas. Arranca con
 * una ruta por parada (depósito → parada) y las va FUSIONANDO en el orden de mayor ahorro
 *   s(i, j) = d(0, i) + d(0, j) − d(i, j)
 * siempre que i y j sean extremos de rutas distintas y la carga combinada entre en la capacidad (kg y m3).
 * El resultado es un conjunto de rutas, cada una una lista ordenada de índices de parada.
 *
 * Reemplaza al empaque greedy (first-fit-decreasing por volumen) por una heurística que mira la geometría
 * real: agrupa paradas que conviene entregar juntas y produce menos kilómetros. Determinista: los ahorros
 * se ordenan con desempate estable por (i, j), así la misma entrada da siempre el mismo reparto.
 */
final readonly class ClarkeWrightSolver
{
    /**
     * Resuelve las rutas para las paradas 1..n de la matriz.
     *
     * @param  array<int, float> $demandsKg  carga en kg por índice de parada (1..n)
     * @param  array<int, float> $demandsM3  carga en m3 por índice de parada (1..n)
     * @param  float             $capacityKg capacidad UTILIZABLE en kg de la ruta (nominal menos holgura)
     * @param  float             $capacityM3 capacidad UTILIZABLE en m3 de la ruta
     * @return list<list<int>>   rutas, cada una lista ordenada de índices de parada (sin el depósito)
     */
    public function solve(DistanceMatrix $matrix, array $demandsKg, array $demandsM3, float $capacityKg, float $capacityM3): array
    {
        $n = $matrix->size() - 1;
        if ($n <= 0) {
            return [];
        }

        // Una ruta por parada al arranque: cada parada es su propia ruta [i].
        /** @var array<int, list<int>> $routes rutaId => lista de paradas */
        $routes = [];
        /** @var array<int, int> $routeOf parada => rutaId */
        $routeOf = [];
        /** @var array<int, float> $loadKg rutaId => carga kg */
        $loadKg = [];
        /** @var array<int, float> $loadM3 rutaId => carga m3 */
        $loadM3 = [];
        for ($i = 1; $i <= $n; $i++) {
            $routes[$i] = [$i];
            $routeOf[$i] = $i;
            $loadKg[$i] = $demandsKg[$i] ?? 0.0;
            $loadM3[$i] = $demandsM3[$i] ?? 0.0;
        }

        foreach ($this->sortedSavings($matrix, $n) as [$i, $j]) {
            $routeI = $routeOf[$i];
            $routeJ = $routeOf[$j];
            if ($routeI === $routeJ) {
                continue;
            }
            // Ambas paradas deben ser EXTREMOS de sus rutas (las interiores no se pueden fusionar).
            if (!$this->isEndpoint($routes[$routeI], $i) || !$this->isEndpoint($routes[$routeJ], $j)) {
                continue;
            }
            // La carga combinada debe entrar en la capacidad utilizable.
            if ($capacityKg < $loadKg[$routeI] + $loadKg[$routeJ] || $capacityM3 < $loadM3[$routeI] + $loadM3[$routeJ]) {
                continue;
            }

            $merged = $this->merge($routes[$routeI], $routes[$routeJ], $i, $j);

            foreach ($merged as $stop) {
                $routeOf[$stop] = $routeI;
            }
            $routes[$routeI] = $merged;
            $loadKg[$routeI] += $loadKg[$routeJ];
            $loadM3[$routeI] += $loadM3[$routeJ];
            unset($routes[$routeJ], $loadKg[$routeJ], $loadM3[$routeJ]);
        }

        return array_values($routes);
    }

    /**
     * Pares (i, j) con 1 <= i < j <= n ordenados por ahorro DESCENDENTE, con desempate estable por (i, j)
     * ascendente. Solo se consideran ahorros positivos (fusionar aleja menos que servir por separado).
     *
     * @return list<array{int, int}>
     */
    private function sortedSavings(DistanceMatrix $matrix, int $n): array
    {
        /** @var list<array{float, int, int}> $savings */
        $savings = [];
        for ($i = 1; $i <= $n; $i++) {
            for ($j = $i + 1; $j <= $n; $j++) {
                $saving = $matrix->distanceMeters(0, $i) + $matrix->distanceMeters(0, $j) - $matrix->distanceMeters($i, $j);
                if ($saving > 0.0) {
                    $savings[] = [$saving, $i, $j];
                }
            }
        }

        usort($savings, static function (array $a, array $b): int {
            // Mayor ahorro primero; a igual ahorro, orden estable por i y luego j.
            return [$b[0], $a[1], $a[2]] <=> [$a[0], $b[1], $b[2]];
        });

        return array_map(static fn (array $s): array => [$s[1], $s[2]], $savings);
    }

    /**
     * @param list<int> $route
     */
    private function isEndpoint(array $route, int $stop): bool
    {
        return $route[0] === $stop || $route[count($route) - 1] === $stop;
    }

    /**
     * Fusiona dos rutas dejando ADYACENTES las paradas i y j (los extremos por los que se unen),
     * reorientando (invirtiendo) la ruta que haga falta.
     *
     * @param  list<int> $routeI
     * @param  list<int> $routeJ
     * @return list<int>
     */
    private function merge(array $routeI, array $routeJ, int $i, int $j): array
    {
        // Dejar i como ÚLTIMO de routeI: si es el primero, se invierte.
        if ($routeI[0] === $i) {
            $routeI = array_reverse($routeI);
        }
        // Dejar j como PRIMERO de routeJ: si es el último, se invierte.
        if ($routeJ[count($routeJ) - 1] === $j) {
            $routeJ = array_reverse($routeJ);
        }

        return array_merge($routeI, $routeJ);
    }
}
