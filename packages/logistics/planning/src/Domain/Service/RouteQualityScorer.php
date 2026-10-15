<?php

declare(strict_types=1);

namespace Logistics\Planning\Domain\Service;

use Logistics\Planning\Domain\ValueObject\DistanceMatrix;
use Logistics\Planning\Domain\ValueObject\RouteQualityScore;

/**
 * Calcula las métricas de CALIDAD de una ruta (R-06): distancia total, tiempo estimado y ocupación del
 * camión. PHP puro, determinista y sin I/O.
 *
 * La distancia/tiempo del recorrido se pueden tomar de la geometría ya calculada y persistida con el plan
 * (fuente preferida, es lo que efectivamente recorrerá el camión) o recomputarse sumando los tramos de la
 * matriz real por carretera en el orden de reparto (camino ABIERTO depósito → paradas, sin regreso al CD,
 * igual que el RouteSequencer). La ocupación contrasta la carga total contra la capacidad del camión.
 */
final readonly class RouteQualityScorer
{
    /**
     * Suma los tramos del recorrido (metros y segundos) recorriendo depósito (índice 0) → paradas en el
     * orden dado, sin tramo de regreso. Útil cuando no hay geometría persistida y hay que estimar desde la
     * matriz.
     *
     * @param  list<int>                 $order índices de parada (1..n de la matriz) en el orden de reparto
     * @return array{0: float, 1: float} [distanciaMetros, duracionSegundos]
     */
    public function legMetrics(DistanceMatrix $matrix, array $order): array
    {
        $distance = 0.0;
        $duration = 0.0;
        $previous = 0; // depósito

        foreach ($order as $stop) {
            $distance += $matrix->distanceMeters($previous, $stop);
            $duration += $matrix->durationSeconds($previous, $stop);
            $previous = $stop;
        }

        return [$distance, $duration];
    }

    /**
     * Arma el score de la ruta a partir de la distancia/tiempo del recorrido y la carga/capacidad del
     * camión. La capacidad puede ser null (desconocida): la ocupación queda null en ese eje.
     */
    public function score(
        float $distanceMeters,
        float $durationSeconds,
        float $loadKg,
        float $loadM3,
        ?float $capacityKg,
        ?float $capacityM3,
    ): RouteQualityScore {
        return RouteQualityScore::of(
            $distanceMeters,
            $durationSeconds,
            $loadKg,
            $loadM3,
            $capacityKg,
            $capacityM3,
        );
    }
}
