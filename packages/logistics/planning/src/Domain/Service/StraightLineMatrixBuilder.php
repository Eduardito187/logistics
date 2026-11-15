<?php

declare(strict_types=1);

namespace Logistics\Planning\Domain\Service;

use Logistics\Planning\Domain\ValueObject\DistanceMatrix;
use Logistics\Planning\Domain\ValueObject\GeoPoint;

/**
 * Arma la matriz de distancias/tiempos SIN ruteador: distancia en línea recta (haversine) corregida por
 * un factor de rodeo (las calles nunca son una recta) y tiempo a una velocidad promedio. PHP puro y
 * determinista: es el respaldo que deja funcionar el asistente de viajes en un entorno sin servicios
 * externos.
 */
final readonly class StraightLineMatrixBuilder
{
    /**
     * @param list<GeoPoint> $points       índice 0 = punto de salida, 1..n = paradas
     * @param float          $speedKmh     velocidad promedio del camión en ciudad
     * @param float          $detourFactor cuánto más largo es el camino real que la recta (1.0 = sin rodeo)
     */
    public function build(array $points, float $speedKmh, float $detourFactor = 1.0): DistanceMatrix
    {
        $points = array_values($points);
        if ($points === []) {
            return DistanceMatrix::fromArrays([[0.0]], [[0.0]]);
        }

        $metersPerSecond = max(1.0, $speedKmh) * 1000.0 / 3600.0;
        $detour = max(1.0, $detourFactor);

        $distances = [];
        $durations = [];
        foreach ($points as $from) {
            $distanceRow = [];
            $durationRow = [];
            foreach ($points as $to) {
                $meters = $from->haversineMeters($to) * $detour;
                $distanceRow[] = $meters;
                $durationRow[] = $meters / $metersPerSecond;
            }
            $distances[] = $distanceRow;
            $durations[] = $durationRow;
        }

        return DistanceMatrix::fromArrays($distances, $durations);
    }
}
