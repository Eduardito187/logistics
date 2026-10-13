<?php

declare(strict_types=1);

namespace Logistics\Planning\Infrastructure\Routing;

use DateTimeImmutable;
use Logistics\Planning\Domain\Contract\OptimizedStop;
use Logistics\Planning\Domain\Contract\RoadMatrixProvider;
use Logistics\Planning\Domain\Contract\RouteOptimizer;
use Logistics\Planning\Domain\Contract\RouteStop;
use Logistics\Planning\Domain\Service\RouteSequencer;
use Logistics\Planning\Domain\ValueObject\Eta;
use Logistics\Planning\Domain\ValueObject\GeoPoint;

/**
 * Motor de ORDEN de paradas POR DEFECTO ('builtin'): ordena una ruta de un camión sobre la MATRIZ real por
 * carretera con vecino más cercano + mejora local 2-opt/Or-opt (RouteSequencer), y arma la ETA acumulada
 * real desde la salida. Reemplaza, detrás del mismo contrato RouteOptimizer, al orden por OSRM /trip del
 * optimizador anterior: resultados deterministas y notablemente mejores que el greedy, sin depender de que
 * el ruteador exponga /trip.
 *
 * Convención igual a la del optimizador anterior: la PRIMERA parada es el punto de salida (índice 0 de la
 * matriz) y se mantiene fija; el resto se reordena. La matriz la provee RoadMatrixProvider (OSRM /table con
 * caché y degradación a haversine), así que esto nunca tumba la generación del plan.
 */
final readonly class BuiltinRouteOptimizer implements RouteOptimizer
{
    public function __construct(
        private RoadMatrixProvider $matrixProvider,
        private RouteSequencer $sequencer,
    ) {}

    public function optimize(DateTimeImmutable $departAt, array $stops): array
    {
        $stops = array_values($stops);
        $count = count($stops);
        if ($count === 0) {
            return [];
        }
        if ($count === 1) {
            return [new OptimizedStop($stops[0]->shipmentId, Eta::at($departAt, 0))];
        }

        $points = array_map(
            static fn (RouteStop $stop): GeoPoint => GeoPoint::of($stop->latitude, $stop->longitude),
            $stops,
        );

        $matrix = $this->matrixProvider->matrixFor($points);

        // El índice 0 (primera parada) es la salida y queda fijo; se reordena 1..n-1.
        $rest = range(1, $count - 1);
        $orderedRest = $this->sequencer->sequence($matrix, $rest);

        $order = array_merge([0], $orderedRest);

        $optimized = [];
        $cumulativeSeconds = 0.0;
        $previous = 0;
        foreach ($order as $position => $stopIndex) {
            if ($position > 0) {
                $cumulativeSeconds += $matrix->durationSeconds($previous, $stopIndex);
            }
            $minutes = (int) round($cumulativeSeconds / 60);
            $optimized[] = new OptimizedStop($stops[$stopIndex]->shipmentId, Eta::at($departAt->modify("+{$minutes} minutes"), $minutes));
            $previous = $stopIndex;
        }

        return $optimized;
    }
}
