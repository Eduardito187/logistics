<?php

declare(strict_types=1);

namespace Logistics\Planning\Application\Service;

use Logistics\Planning\Domain\Contract\RoadMatrixProvider;
use Logistics\Planning\Domain\Service\StraightLineMatrixBuilder;
use Logistics\Planning\Domain\ValueObject\DistanceMatrix;
use Logistics\Planning\Domain\ValueObject\GeoPoint;
use Throwable;

/**
 * Fuente de la matriz de distancias/tiempos del asistente de viajes. Intenta la matriz REAL por carretera
 * (el proveedor existente sobre OSRM, con tope de espera corto) y, si está deshabilitada, falla o devuelve
 * algo que no calza con los puntos pedidos, usa el respaldo LOCAL determinista (línea recta con factor de
 * rodeo y velocidad promedio). Nunca deja al llamador sin matriz ni depende de un servicio externo.
 */
final readonly class TripMatrixSource
{
    public function __construct(
        private RoadMatrixProvider $road,
        private StraightLineMatrixBuilder $local,
        private TripBuilderSettings $settings,
    ) {}

    /**
     * @param list<GeoPoint> $points índice 0 = punto de salida, 1..n = paradas
     */
    public function matrixFor(array $points): DistanceMatrix
    {
        $points = array_values($points);

        if ($this->settings->useRoadMatrix && count($points) > 1) {
            try {
                $matrix = $this->road->matrixFor($points);
                if ($matrix->size() === count($points)) {
                    return $matrix;
                }
            } catch (Throwable) {
                // El ruteador no respondió: se sigue con el respaldo local.
            }
        }

        return $this->local->build($points, $this->settings->averageSpeedKmh, $this->settings->detourFactor);
    }
}
