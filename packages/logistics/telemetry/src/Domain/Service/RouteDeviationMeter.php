<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Domain\Service;

use Logistics\Telemetry\Domain\ValueObject\GeoPoint;

/**
 * Mide la distancia (metros) de una posición a la geometría de una ruta (polilínea). Servicio puro.
 *
 * Proyecta localmente (equirectangular centrada en el punto, válida a escala urbana/departamental) y toma la
 * distancia mínima a cada segmento. Para una polilínea de un solo punto devuelve la distancia a ese punto.
 */
final readonly class RouteDeviationMeter
{
    private const float EARTH_RADIUS_M = 6_371_000.0;

    /**
     * @param list<GeoPoint> $polyline geometría de la ruta en orden
     *
     * @return float|null distancia en metros, o null si la polilínea está vacía
     */
    public function distanceToRoute(GeoPoint $point, array $polyline): ?float
    {
        if ($polyline === []) {
            return null;
        }
        if (count($polyline) === 1) {
            return $point->distanceMetersTo($polyline[0]);
        }

        $cosLat = cos(deg2rad($point->lat));
        $best = INF;
        $previous = $this->project($polyline[0], $point, $cosLat);
        $count = count($polyline);

        for ($i = 1; $i < $count; $i++) {
            $current = $this->project($polyline[$i], $point, $cosLat);
            $best = min($best, $this->distanceOriginToSegment($previous, $current));
            $previous = $current;
        }

        return $best;
    }

    /**
     * Proyección local (metros) de `p` respecto del origen `origin`.
     *
     * @return array{0: float, 1: float}
     */
    private function project(GeoPoint $p, GeoPoint $origin, float $cosLat): array
    {
        return [
            deg2rad($p->lng - $origin->lng) * $cosLat * self::EARTH_RADIUS_M,
            deg2rad($p->lat - $origin->lat) * self::EARTH_RADIUS_M,
        ];
    }

    /**
     * Distancia del origen (0,0) —la posición proyectada— al segmento AB en el plano.
     *
     * @param array{0: float, 1: float} $a
     * @param array{0: float, 1: float} $b
     */
    private function distanceOriginToSegment(array $a, array $b): float
    {
        $dx = $b[0] - $a[0];
        $dy = $b[1] - $a[1];
        $lengthSq = $dx * $dx + $dy * $dy;

        if ($lengthSq <= 0.0) {
            return sqrt($a[0] * $a[0] + $a[1] * $a[1]);
        }

        // Parámetro de la proyección del origen sobre la recta AB, acotado al segmento.
        $t = max(0.0, min(1.0, -($a[0] * $dx + $a[1] * $dy) / $lengthSq));
        $x = $a[0] + $t * $dx;
        $y = $a[1] + $t * $dy;

        return sqrt($x * $x + $y * $y);
    }
}
