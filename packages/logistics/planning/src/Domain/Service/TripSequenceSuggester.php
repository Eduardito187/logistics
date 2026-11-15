<?php

declare(strict_types=1);

namespace Logistics\Planning\Domain\Service;

use Logistics\Planning\Domain\ValueObject\DistanceMatrix;
use Logistics\Planning\Domain\ValueObject\TimeWindow;

/**
 * Sugiere el ORDEN de reparto de un viaje armado a mano (un camión, una lista de pedidos) y calcula su
 * línea de tiempo. PHP puro, determinista y sin I/O: es el respaldo local del asistente de viajes, así
 * funciona igual con o sin ruteador externo (la matriz puede venir de OSRM o de la línea recta).
 *
 * Convención de la matriz: índice 0 = punto de salida (base del camión), 1..n = paradas.
 *
 * Algoritmo, en tres pasos:
 *  1. VECINO MÁS CERCANO desde la salida con las paradas SIN ventana horaria (el camino corto base).
 *  2. INSERCIÓN de cada parada CON ventana (primero la que cierra antes) en la posición que deja el menor
 *     retraso total y, a igual retraso, el recorrido más corto: así se llega dentro de la hora que eligió
 *     el cliente siempre que exista un orden que lo permita.
 *  3. Mejora local 2-opt con el mismo criterio (retraso primero, duración después).
 *
 * El reloj incluye la ESPERA (llegar antes de que abra la ventana corre la llegada a la apertura) y el
 * TIEMPO DE SERVICIO de cada parada (lo que tarda la entrega antes de seguir viaje).
 */
final readonly class TripSequenceSuggester
{
    /** Tope de pasadas de la mejora local (red de seguridad; el descenso es estricto y termina solo). */
    private const MAX_PASSES = 20;

    /** Por encima de esta cantidad de paradas se omite la mejora local (costo cuadrático por pasada). */
    private const MAX_STOPS_FOR_IMPROVEMENT = 80;

    /** Diferencia mínima (segundos) para considerar que un orden es mejor que otro. */
    private const EPSILON = 1e-6;

    /**
     * Orden de reparto sugerido para las paradas dadas.
     *
     * @param  list<int>              $stops          índices de parada (1..n de la matriz)
     * @param  array<int, TimeWindow> $windows        ventana por índice de parada (segundos desde la salida)
     * @param  float                  $serviceSeconds tiempo de servicio por parada
     * @return list<int>              las mismas paradas, en el orden sugerido
     */
    public function suggest(DistanceMatrix $matrix, array $stops, array $windows = [], float $serviceSeconds = 0.0): array
    {
        $free = [];
        $windowed = [];
        foreach (array_values(array_unique($stops)) as $stop) {
            if (isset($windows[$stop])) {
                $windowed[] = $stop;
            } else {
                $free[] = $stop;
            }
        }

        $order = $this->nearestNeighbor($matrix, $free);

        // Las ventanas que cierran antes se ubican primero; desempate por apertura y por índice (estable).
        usort($windowed, static function (int $a, int $b) use ($windows): int {
            return [$windows[$a]->latestSeconds ?? INF, $windows[$a]->earliestSeconds, $a]
                <=> [$windows[$b]->latestSeconds ?? INF, $windows[$b]->earliestSeconds, $b];
        });

        foreach ($windowed as $stop) {
            $order = $this->insertAtBestPosition($matrix, $order, $stop, $windows, $serviceSeconds);
        }

        if (count($order) < 3 || count($order) > self::MAX_STOPS_FOR_IMPROVEMENT) {
            return $order;
        }

        return $this->improve($matrix, $order, $windows, $serviceSeconds);
    }

    /**
     * Línea de tiempo de un orden dado: llegada efectiva a cada parada (con espera de ventana), si llega
     * tarde respecto del cierre, y los totales del recorrido (camino abierto: no vuelve a la base).
     *
     * @param  list<int>                                                                                                                $order
     * @param  array<int, TimeWindow>                                                                                                   $windows
     * @return array{arrivals: list<float>, late: list<bool>, distance_meters: float, duration_seconds: float, lateness_seconds: float}
     */
    public function timeline(DistanceMatrix $matrix, array $order, array $windows = [], float $serviceSeconds = 0.0): array
    {
        $arrivals = [];
        $late = [];
        $distance = 0.0;
        $lateness = 0.0;
        $clock = 0.0;
        $previous = 0; // punto de salida

        foreach ($order as $stop) {
            $arrival = $clock + $matrix->durationSeconds($previous, $stop);
            $stopLateness = 0.0;

            $window = $windows[$stop] ?? null;
            if ($window !== null) {
                $arrival = $window->effectiveArrivalFrom($arrival);
                $stopLateness = $window->latenessAt($arrival);
            }

            $arrivals[] = $arrival;
            $late[] = $stopLateness > 0.0;
            $lateness += $stopLateness;
            $distance += $matrix->distanceMeters($previous, $stop);
            $clock = $arrival + $serviceSeconds;
            $previous = $stop;
        }

        return [
            'arrivals' => $arrivals,
            'late' => $late,
            'distance_meters' => $distance,
            'duration_seconds' => $clock,
            'lateness_seconds' => $lateness,
        ];
    }

    /**
     * Vecino más cercano arrancando en el punto de salida (índice 0). Desempata por índice de parada.
     *
     * @param  list<int> $stops
     * @return list<int>
     */
    private function nearestNeighbor(DistanceMatrix $matrix, array $stops): array
    {
        $remaining = array_values($stops);
        $order = [];
        $current = 0;

        while ($remaining !== []) {
            $bestKey = 0;
            $bestDuration = INF;
            foreach ($remaining as $key => $stop) {
                $duration = $matrix->durationSeconds($current, $stop);
                if ($duration < $bestDuration - self::EPSILON) {
                    $bestDuration = $duration;
                    $bestKey = $key;
                }
            }

            $current = $remaining[$bestKey];
            $order[] = $current;
            unset($remaining[$bestKey]);
            $remaining = array_values($remaining);
        }

        return $order;
    }

    /**
     * Inserta la parada en la posición que deja el mejor recorrido (menos retraso; a igual retraso, menos
     * duración). A igualdad total gana la posición más temprana (determinista).
     *
     * @param  list<int>              $order
     * @param  array<int, TimeWindow> $windows
     * @return list<int>
     */
    private function insertAtBestPosition(DistanceMatrix $matrix, array $order, int $stop, array $windows, float $serviceSeconds): array
    {
        $best = null;
        $bestScore = null;

        for ($position = 0; $position <= count($order); $position++) {
            $candidate = array_merge(array_slice($order, 0, $position), [$stop], array_slice($order, $position));
            $score = $this->score($matrix, $candidate, $windows, $serviceSeconds);

            if ($bestScore === null || $this->better($score, $bestScore)) {
                $best = $candidate;
                $bestScore = $score;
            }
        }

        return $best ?? [$stop];
    }

    /**
     * Mejora 2-opt: invierte el segmento que más mejora el recorrido, hasta que ninguno mejore.
     *
     * @param  list<int>              $order
     * @param  array<int, TimeWindow> $windows
     * @return list<int>
     */
    private function improve(DistanceMatrix $matrix, array $order, array $windows, float $serviceSeconds): array
    {
        $count = count($order);

        for ($pass = 0; $pass < self::MAX_PASSES; $pass++) {
            $bestScore = $this->score($matrix, $order, $windows, $serviceSeconds);
            $bestOrder = null;

            for ($i = 0; $i < $count - 1; $i++) {
                for ($j = $i + 1; $j < $count; $j++) {
                    $candidate = array_merge(
                        array_slice($order, 0, $i),
                        array_reverse(array_slice($order, $i, $j - $i + 1)),
                        array_slice($order, $j + 1),
                    );
                    $score = $this->score($matrix, $candidate, $windows, $serviceSeconds);
                    if ($this->better($score, $bestScore)) {
                        $bestScore = $score;
                        $bestOrder = $candidate;
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
     * Puntaje de un orden: [retraso total, duración total], ambos en segundos.
     *
     * @param  list<int>                 $order
     * @param  array<int, TimeWindow>    $windows
     * @return array{0: float, 1: float}
     */
    private function score(DistanceMatrix $matrix, array $order, array $windows, float $serviceSeconds): array
    {
        $timeline = $this->timeline($matrix, $order, $windows, $serviceSeconds);

        return [$timeline['lateness_seconds'], $timeline['duration_seconds']];
    }

    /**
     * ¿El puntaje $a es estrictamente mejor que $b? Primero el retraso, después la duración.
     *
     * @param array{0: float, 1: float} $a
     * @param array{0: float, 1: float} $b
     */
    private function better(array $a, array $b): bool
    {
        if ($a[0] < $b[0] - self::EPSILON) {
            return true;
        }
        if ($a[0] > $b[0] + self::EPSILON) {
            return false;
        }

        return $a[1] < $b[1] - self::EPSILON;
    }
}
