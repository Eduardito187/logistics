<?php

declare(strict_types=1);

namespace Logistics\Planning\Domain\Service;

use Logistics\Planning\Domain\ValueObject\DistanceMatrix;

/**
 * Ordena las paradas de UNA ruta minimizando el tiempo de viaje por carretera. PHP puro, determinista y
 * sin I/O: trabaja sobre la matriz de tiempos ya calculada (índice 0 = depósito/CD, 1..n = paradas).
 *
 * Tres pasos encadenados, de bueno a mejor:
 *  1. Orden inicial por VECINO MÁS CERCANO desde el depósito (heurística clásica del TSP).
 *  2. Mejora local 2-opt: invierte segmentos del recorrido mientras reduzca el tiempo total.
 *  3. Mejora local Or-opt: reubica segmentos de 1 a 3 paradas en una mejor posición.
 *
 * Es un camino ABIERTO (el camión sale del depósito y termina en la última entrega; no vuelve al CD), así
 * que el costo es la suma de los tramos a lo largo de la secuencia, sin tramo de regreso. El descenso es
 * estricto (cada movimiento baja el costo), de modo que termina; el tope de pasadas es solo una red de
 * seguridad. Supera al orden greedy actual: el resultado es notablemente mejor y determinista.
 */
final readonly class RouteSequencer
{
    /** Tope de pasadas de mejora local (red de seguridad; el descenso estricto ya garantiza el fin). */
    private const MAX_PASSES = 40;

    /** Largo máximo de segmento que Or-opt reubica. */
    private const MAX_OR_OPT_SEGMENT = 3;

    /**
     * Devuelve las paradas en el orden de reparto óptimo encontrado.
     *
     * @param  list<int> $stops índices de parada (1..n de la matriz), en cualquier orden inicial
     * @return list<int> las mismas paradas, reordenadas
     */
    public function sequence(DistanceMatrix $matrix, array $stops): array
    {
        if (count($stops) < 2) {
            return array_values($stops);
        }

        $order = $this->nearestNeighbor($matrix, $stops);
        $order = $this->twoOpt($matrix, $order);

        return $this->orOpt($matrix, $order);
    }

    /**
     * Orden inicial por vecino más cercano arrancando en el depósito (índice 0).
     *
     * @param  list<int> $stops
     * @return list<int>
     */
    private function nearestNeighbor(DistanceMatrix $matrix, array $stops): array
    {
        $remaining = array_values($stops);
        $order = [];
        $current = 0; // depósito

        while ($remaining !== []) {
            $bestKey = null;
            $bestDuration = INF;
            foreach ($remaining as $key => $stop) {
                $duration = $matrix->durationSeconds($current, $stop);
                if ($duration < $bestDuration) {
                    $bestDuration = $duration;
                    $bestKey = $key;
                }
            }
            if ($bestKey === null) {
                break;
            }
            $current = $remaining[$bestKey];
            $order[] = $current;
            unset($remaining[$bestKey]);
            $remaining = array_values($remaining);
        }

        return $order;
    }

    /**
     * Mejora 2-opt (descenso más pronunciado): en cada pasada busca la inversión de segmento que más baja
     * el costo y la aplica; se detiene cuando ninguna inversión mejora.
     *
     * @param  list<int> $order
     * @return list<int>
     */
    private function twoOpt(DistanceMatrix $matrix, array $order): array
    {
        $count = count($order);

        for ($pass = 0; $pass < self::MAX_PASSES; $pass++) {
            $bestDelta = -1e-6; // exige una mejora mínima para evitar lazos por ruido de punto flotante
            $bestI = -1;
            $bestJ = -1;
            $currentCost = $this->pathCost($matrix, $order);

            for ($i = 0; $i < $count - 1; $i++) {
                for ($j = $i + 1; $j < $count; $j++) {
                    $candidate = $this->reverseSegment($order, $i, $j);
                    $delta = $this->pathCost($matrix, $candidate) - $currentCost;
                    if ($delta < $bestDelta) {
                        $bestDelta = $delta;
                        $bestI = $i;
                        $bestJ = $j;
                    }
                }
            }

            if ($bestI < 0) {
                break;
            }
            $order = $this->reverseSegment($order, $bestI, $bestJ);
        }

        return $order;
    }

    /**
     * Mejora Or-opt: reubica segmentos de 1..3 paradas en la mejor posición del recorrido.
     *
     * @param  list<int> $order
     * @return list<int>
     */
    private function orOpt(DistanceMatrix $matrix, array $order): array
    {
        $count = count($order);

        for ($pass = 0; $pass < self::MAX_PASSES; $pass++) {
            $bestDelta = -1e-6;
            /** @var list<int>|null $bestOrder */
            $bestOrder = null;
            $currentCost = $this->pathCost($matrix, $order);

            for ($length = 1; $length <= self::MAX_OR_OPT_SEGMENT; $length++) {
                for ($start = 0; $start + $length <= $count; $start++) {
                    $segment = array_slice($order, $start, $length);
                    $rest = array_merge(array_slice($order, 0, $start), array_slice($order, $start + $length));

                    for ($insert = 0; $insert <= count($rest); $insert++) {
                        if ($insert === $start && $length <= count($rest)) {
                            // Reinsertar en la misma posición no cambia nada; se omite.
                            continue;
                        }
                        $candidate = array_merge(
                            array_slice($rest, 0, $insert),
                            $segment,
                            array_slice($rest, $insert),
                        );
                        $delta = $this->pathCost($matrix, $candidate) - $currentCost;
                        if ($delta < $bestDelta) {
                            $bestDelta = $delta;
                            $bestOrder = $candidate;
                        }
                    }
                }
            }

            if ($bestOrder === null) {
                break;
            }
            $order = $bestOrder;
        }

        return $order;
    }

    /**
     * Costo (segundos) del camino abierto depósito → paradas en el orden dado, sin tramo de regreso.
     *
     * @param list<int> $order
     */
    private function pathCost(DistanceMatrix $matrix, array $order): float
    {
        $cost = 0.0;
        $previous = 0; // depósito
        foreach ($order as $stop) {
            $cost += $matrix->durationSeconds($previous, $stop);
            $previous = $stop;
        }

        return $cost;
    }

    /**
     * Devuelve una copia de $order con el segmento [$i..$j] invertido.
     *
     * @param  list<int> $order
     * @return list<int>
     */
    private function reverseSegment(array $order, int $i, int $j): array
    {
        $segment = array_reverse(array_slice($order, $i, $j - $i + 1));

        return array_merge(
            array_slice($order, 0, $i),
            $segment,
            array_slice($order, $j + 1),
        );
    }
}
