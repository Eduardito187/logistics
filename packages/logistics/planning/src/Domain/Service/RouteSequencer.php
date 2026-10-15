<?php

declare(strict_types=1);

namespace Logistics\Planning\Domain\Service;

use Logistics\Planning\Domain\ValueObject\DistanceMatrix;
use Logistics\Planning\Domain\ValueObject\TimeWindow;

/**
 * Ordena las paradas de UNA ruta minimizando el tiempo de viaje por carretera. PHP puro, determinista y
 * sin I/O: trabaja sobre la matriz de tiempos ya calculada (índice 0 = depósito/CD, 1..n = paradas).
 *
 * Tres pasos encadenados, de bueno a mejor:
 *  1. Orden inicial por VECINO MÁS CERCANO desde el depósito (heurística clásica del TSP). Con ventanas,
 *     se arranca por FECHA DE CIERRE más temprana (EDD), un mejor punto de partida cuando hay franjas.
 *  2. Mejora local 2-opt: invierte segmentos del recorrido mientras reduzca el costo total.
 *  3. Mejora local Or-opt: reubica segmentos de 1 a 3 paradas en una mejor posición.
 *
 * VENTANAS HORARIAS (modo slots): si una parada trae su franja (TimeWindow en segundos desde la salida),
 * el costo deja de ser solo viaje y pasa a ser viaje + ESPERA (llegar antes de la apertura obliga a esperar)
 * + una PENALIZACIÓN fuerte por RETRASO (llegar después del cierre). Así el mismo descenso local ordena las
 * paradas respetando sus ventanas: no se agenda una parada antes de su franja ni después. Sin ventanas
 * (mapa vacío) el costo se reduce EXACTAMENTE al tiempo de viaje y el comportamiento es el de siempre.
 *
 * Es un camino ABIERTO (el camión sale del depósito y termina en la última entrega; no vuelve al CD), así
 * que el costo es la suma de los tramos a lo largo de la secuencia, sin tramo de regreso. El descenso es
 * estricto (cada movimiento baja el costo), de modo que termina; el tope de pasadas es solo una red de
 * seguridad. El resultado es determinista.
 */
final readonly class RouteSequencer
{
    /** Tope de pasadas de mejora local (red de seguridad; el descenso estricto ya garantiza el fin). */
    private const MAX_PASSES = 40;

    /** Largo máximo de segmento que Or-opt reubica. */
    private const MAX_OR_OPT_SEGMENT = 3;

    /**
     * Peso de la penalización por segundo de RETRASO respecto del tiempo de viaje. Tan grande que cualquier
     * orden que respete las ventanas le gana a uno que las incumpla, pero finito para que, cuando NINGÚN
     * orden sea factible, el solver elija igual el que menos incumple (en vez de quedarse sin solución).
     */
    private const LATENESS_PENALTY = 1_000_000.0;

    /**
     * Devuelve las paradas en el orden de reparto óptimo encontrado.
     *
     * @param  list<int>              $stops   índices de parada (1..n de la matriz), en cualquier orden inicial
     * @param  array<int, TimeWindow> $windows ventana por índice de parada; sin entrada = sin restricción
     * @return list<int>              las mismas paradas, reordenadas
     */
    public function sequence(DistanceMatrix $matrix, array $stops, array $windows = []): array
    {
        if (count($stops) < 2) {
            return array_values($stops);
        }

        $order = $this->initialOrder($matrix, $stops, $windows);
        $order = $this->twoOpt($matrix, $order, $windows);

        return $this->orOpt($matrix, $order, $windows);
    }

    /**
     * RE-SECUENCIA INCREMENTAL (R-05): mejora un orden EXISTENTE con 2-opt/Or-opt SIN volver a sembrar el
     * orden inicial (vecino más cercano / EDD). Arranca del orden que ya tiene la ruta y solo aplica
     * movimientos que BAJAN el costo, así el resultado NUNCA empeora el orden actual ("mejora o mantiene")
     * y es idempotente: re-aplicarla sobre su propia salida no la cambia (es un punto fijo del descenso).
     *
     * Es lo que usa la re-optimización ante un cambio (nuevo pedido, cancelación, incidente): re-secuenciar
     * la ruta afectada sin rehacer el día. Con menos de dos paradas, devuelve el orden tal cual.
     *
     * @param  list<int>              $order   orden actual de las paradas (índices 1..n de la matriz)
     * @param  array<int, TimeWindow> $windows ventana por índice de parada; sin entrada = sin restricción
     * @return list<int>              el mismo conjunto de paradas, re-secuenciado (igual o mejor)
     */
    public function improve(DistanceMatrix $matrix, array $order, array $windows = []): array
    {
        if (count($order) < 2) {
            return array_values($order);
        }

        $improved = $this->twoOpt($matrix, array_values($order), $windows);

        return $this->orOpt($matrix, $improved, $windows);
    }

    /**
     * Hora de llegada EFECTIVA (segundos desde la salida) a cada parada en el orden dado, aplicando la
     * espera de las ventanas: si se llega antes de la apertura, la llegada se corre a la apertura. Es lo que
     * el motor usa para fijar la ETA real de cada parada respetando su franja.
     *
     * @param  list<int>              $order
     * @param  array<int, TimeWindow> $windows
     * @return list<float>            segundos desde la salida, alineados con $order
     */
    public function arrivalSeconds(DistanceMatrix $matrix, array $order, array $windows = []): array
    {
        $arrivals = [];
        $clock = 0.0;
        $previous = 0; // depósito

        foreach ($order as $stop) {
            $arrival = $clock + $matrix->durationSeconds($previous, $stop);
            $window = $windows[$stop] ?? null;
            if ($window !== null) {
                $arrival = $window->effectiveArrivalFrom($arrival);
            }
            $arrivals[] = $arrival;
            $clock = $arrival;
            $previous = $stop;
        }

        return $arrivals;
    }

    /**
     * Orden inicial. Sin ventanas: vecino más cercano desde el depósito (como siempre). Con ventanas:
     * fecha de cierre más temprana primero (EDD), un arranque mucho mejor cuando hay franjas que respetar.
     *
     * @param  list<int>              $stops
     * @param  array<int, TimeWindow> $windows
     * @return list<int>
     */
    private function initialOrder(DistanceMatrix $matrix, array $stops, array $windows): array
    {
        return $windows === []
            ? $this->nearestNeighbor($matrix, $stops)
            : $this->earliestDueSeed($stops, $windows);
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
     * Semilla EDD (earliest due date): primero las paradas cuya ventana CIERRA antes (las más urgentes),
     * desempatando por apertura más temprana y, por último, por índice de parada para que sea estable. Las
     * paradas sin ventana van al final (no tienen urgencia propia). El 2-opt/Or-opt afina desde acá.
     *
     * @param  list<int>              $stops
     * @param  array<int, TimeWindow> $windows
     * @return list<int>
     */
    private function earliestDueSeed(array $stops, array $windows): array
    {
        $order = array_values($stops);

        usort($order, static function (int $a, int $b) use ($windows): int {
            $latestA = isset($windows[$a]) ? ($windows[$a]->latestSeconds ?? INF) : INF;
            $latestB = isset($windows[$b]) ? ($windows[$b]->latestSeconds ?? INF) : INF;
            $earliestA = isset($windows[$a]) ? $windows[$a]->earliestSeconds : 0.0;
            $earliestB = isset($windows[$b]) ? $windows[$b]->earliestSeconds : 0.0;

            return [$latestA, $earliestA, $a] <=> [$latestB, $earliestB, $b];
        });

        return $order;
    }

    /**
     * Mejora 2-opt (descenso más pronunciado): en cada pasada busca la inversión de segmento que más baja
     * el costo y la aplica; se detiene cuando ninguna inversión mejora.
     *
     * @param  list<int>              $order
     * @param  array<int, TimeWindow> $windows
     * @return list<int>
     */
    private function twoOpt(DistanceMatrix $matrix, array $order, array $windows): array
    {
        $count = count($order);

        for ($pass = 0; $pass < self::MAX_PASSES; $pass++) {
            $bestDelta = -1e-6; // exige una mejora mínima para evitar lazos por ruido de punto flotante
            $bestI = -1;
            $bestJ = -1;
            $currentCost = $this->cost($matrix, $order, $windows);

            for ($i = 0; $i < $count - 1; $i++) {
                for ($j = $i + 1; $j < $count; $j++) {
                    $candidate = $this->reverseSegment($order, $i, $j);
                    $delta = $this->cost($matrix, $candidate, $windows) - $currentCost;
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
     * @param  list<int>              $order
     * @param  array<int, TimeWindow> $windows
     * @return list<int>
     */
    private function orOpt(DistanceMatrix $matrix, array $order, array $windows): array
    {
        $count = count($order);

        for ($pass = 0; $pass < self::MAX_PASSES; $pass++) {
            $bestDelta = -1e-6;
            /** @var list<int>|null $bestOrder */
            $bestOrder = null;
            $currentCost = $this->cost($matrix, $order, $windows);

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
                        $delta = $this->cost($matrix, $candidate, $windows) - $currentCost;
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
     * Sin ventanas es la pura suma de los tramos de viaje. Con ventanas suma, además, la ESPERA de llegar
     * antes de cada apertura y una PENALIZACIÓN por el RETRASO respecto de cada cierre; así el descenso local
     * ordena respetando las franjas. El reloj avanza con el arribo efectivo (nunca antes de la apertura).
     *
     * @param list<int>              $order
     * @param array<int, TimeWindow> $windows
     */
    private function cost(DistanceMatrix $matrix, array $order, array $windows): float
    {
        $cost = 0.0;
        $lateness = 0.0;
        $clock = 0.0;
        $previous = 0; // depósito

        foreach ($order as $stop) {
            $travel = $matrix->durationSeconds($previous, $stop);
            $cost += $travel;
            $arrival = $clock + $travel;

            $window = $windows[$stop] ?? null;
            if ($window !== null) {
                $wait = $window->waitAt($arrival);
                $cost += $wait;
                $arrival += $wait;
                $lateness += $window->latenessAt($arrival);
            }

            $clock = $arrival;
            $previous = $stop;
        }

        return $cost + self::LATENESS_PENALTY * $lateness;
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
