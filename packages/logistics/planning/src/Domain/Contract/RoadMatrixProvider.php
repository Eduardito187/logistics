<?php

declare(strict_types=1);

namespace Logistics\Planning\Domain\Contract;

use Logistics\Planning\Domain\ValueObject\DistanceMatrix;
use Logistics\Planning\Domain\ValueObject\GeoPoint;

/**
 * Puerto que entrega la MATRIZ de distancias/tiempos por carretera para un conjunto ORDENADO de puntos
 * (índice 0 = origen/CD, 1..n = paradas). El dominio define QUÉ necesita; la implementación (OSRM /table
 * con caché en Valkey y degradación a haversine, en lotes para conjuntos grandes) vive en Infrastructure.
 *
 * Es la base del ruteo real: Clarke-Wright y 2-opt trabajan sobre esta matriz, no sobre la línea recta.
 */
interface RoadMatrixProvider
{
    /**
     * Matriz NxN para los puntos dados, en el mismo orden recibido.
     *
     * @param list<GeoPoint> $points al menos un punto; el índice 0 es el origen por convención
     */
    public function matrixFor(array $points): DistanceMatrix;
}
