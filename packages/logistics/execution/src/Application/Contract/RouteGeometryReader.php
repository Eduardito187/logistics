<?php

declare(strict_types=1);

namespace Logistics\Execution\Application\Contract;

/**
 * Puerta de lectura para obtener la GEOMETRÍA de la ruta por calles, que se calcula y persiste en el plan
 * (planning), no en Ejecución. La ruta del chofer y el plan comparten identidad por (vehículo, día), así
 * que se resuelve por ese par sin acoplar el dominio de Ejecución al de planning.
 *
 * Devuelve la forma primitiva ya lista para la API (esquema RouteGeometry: GeoJSON LineString + totales),
 * o null si el plan no existe o aún no tiene geometría calculada.
 */
interface RouteGeometryReader
{
    /**
     * @param  string                    $vehicleId id del vehículo de la ruta
     * @param  string                    $routeDate fecha de la ruta en formato 'Y-m-d'
     * @return array<string, mixed>|null geometría primitiva (type/coordinates/distance_meters/duration_seconds) o null
     */
    public function forVehicleAndDate(string $vehicleId, string $routeDate): ?array;
}
