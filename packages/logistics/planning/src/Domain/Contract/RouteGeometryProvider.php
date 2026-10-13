<?php

declare(strict_types=1);

namespace Logistics\Planning\Domain\Contract;

use Logistics\Planning\Domain\ValueObject\GeoPoint;
use Logistics\Planning\Domain\ValueObject\RouteGeometry;

/**
 * Puerto que calcula la GEOMETRÍA de la ruta por calles (polilínea) a partir de los puntos en el ORDEN
 * final de reparto. El dominio define QUÉ necesita; la implementación (OSRM /route con overview=full y
 * geometries=geojson, degradando a la línea recta entre paradas si el ruteador no responde) vive en
 * Infrastructure.
 *
 * Se calcula en el servidor y se persiste con el plan: el mapa la pinta sin depender del navegador.
 */
interface RouteGeometryProvider
{
    /**
     * Geometría del recorrido que pasa por los puntos en el orden dado, o null si no se pudo calcular ni
     * siquiera degradando (p. ej. menos de dos puntos).
     *
     * @param list<GeoPoint> $orderedPoints origen seguido de las paradas en orden de reparto
     */
    public function geometryFor(array $orderedPoints): ?RouteGeometry;
}
