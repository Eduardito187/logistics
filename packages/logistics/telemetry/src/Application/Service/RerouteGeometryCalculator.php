<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Application\Service;

use Logistics\Telemetry\Application\Contract\RerouteGeometryCache;
use Logistics\Telemetry\Domain\Contract\RoadGeometryEstimator;
use Logistics\Telemetry\Domain\ValueObject\GeoPoint;
use Throwable;

/**
 * Geometría del reruteo: la polilínea desde la posición ACTUAL del camión hasta su SIGUIENTE parada pendiente,
 * para que la app redibuje la ruta cuando el camión está FUERA de su ruta planificada. Solo tiene sentido con
 * `offRoute` true; si no, no hay geometría de reruteo (null).
 *
 * Dos caminos, para no hacer I/O externo dentro de un request (ADR 002):
 *  - LECTURA (`forRead`, dentro del request): sin I/O. Devuelve lo que el scheduler dejó cacheado o, si no hay,
 *    la línea recta de dos puntos (posición → próxima parada).
 *  - PESADO (`refresh`, comando programado/cola): calcula la polilínea por calles con OSRM y la CACHEA en forma
 *    primitiva; fuera de ruta sin geometría vial cachea la línea recta. Si el vehículo no está fuera de ruta,
 *    limpia la caché (la geometría vieja no debe reaparecer en la próxima lectura).
 */
final readonly class RerouteGeometryCalculator
{
    public function __construct(private RerouteGeometryCache $cache) {}

    /**
     * Camino de LECTURA (request): nunca hace I/O externo.
     *
     * @param  array{lat: float, lng: float}|null       $position posición actual del camión
     * @param  array{lat: float, lng: float}|null       $nextStop próxima parada pendiente con coordenadas
     * @return list<array{lat: float, lng: float}>|null
     */
    public function forRead(string $vehicleId, bool $offRoute, ?array $position, ?array $nextStop): ?array
    {
        $straight = $this->straightLine($offRoute, $position, $nextStop);
        if ($straight === null) {
            return null;
        }

        return $this->cache->get($vehicleId) ?? $straight;
    }

    /**
     * Camino PESADO (scheduler/cola): calcula la geometría vial con OSRM y la cachea. No lanza; ante cualquier
     * fallo del backend cachea la línea recta, para que la lectura siempre tenga algo que dibujar.
     *
     * @param array{lat: float, lng: float}|null $position posición actual del camión
     * @param array{lat: float, lng: float}|null $nextStop próxima parada pendiente con coordenadas
     */
    public function refresh(string $vehicleId, bool $offRoute, ?array $position, ?array $nextStop, RoadGeometryEstimator $road): void
    {
        $straight = $this->straightLine($offRoute, $position, $nextStop);
        if ($straight === null) {
            // No está fuera de ruta (o faltan puntos): no debe quedar geometría vieja cacheada.
            $this->cache->forget($vehicleId);

            return;
        }

        $this->cache->put($vehicleId, $this->roadOrStraight($road, $position, $nextStop, $straight));
    }

    /**
     * Coordenadas de la próxima parada pendiente CON coordenadas de una lista de paradas restantes (en orden
     * de visita). Null si ninguna parada tiene coordenadas.
     *
     * @param  list<array<string, mixed>>         $stops
     * @return array{lat: float, lng: float}|null
     */
    public static function firstStopWithCoords(array $stops): ?array
    {
        foreach ($stops as $stop) {
            $lat = $stop['lat'] ?? null;
            $lng = $stop['lng'] ?? null;
            if (is_numeric($lat) && is_numeric($lng)) {
                return ['lat' => (float) $lat, 'lng' => (float) $lng];
            }
        }

        return null;
    }

    /**
     * Polilínea vial OSRM si el backend respondió; si no, la línea recta de respaldo. No lanza.
     *
     * @param  array{lat: float, lng: float}|null  $position
     * @param  array{lat: float, lng: float}|null  $nextStop
     * @param  list<array{lat: float, lng: float}> $straight
     * @return list<array{lat: float, lng: float}>
     */
    private function roadOrStraight(RoadGeometryEstimator $road, ?array $position, ?array $nextStop, array $straight): array
    {
        if ($position === null || $nextStop === null) {
            return $straight;
        }

        try {
            $geometry = $road->geometry(
                GeoPoint::of($position['lat'], $position['lng']),
                GeoPoint::of($nextStop['lat'], $nextStop['lng']),
            );
        } catch (Throwable) {
            return $straight;
        }

        return $geometry ?? $straight;
    }

    /**
     * Línea recta (dos puntos: posición → próxima parada) cuando corresponde, o null si el vehículo no está
     * fuera de ruta o falta alguno de los dos puntos.
     *
     * @param  array{lat: float, lng: float}|null       $position
     * @param  array{lat: float, lng: float}|null       $nextStop
     * @return list<array{lat: float, lng: float}>|null
     */
    private function straightLine(bool $offRoute, ?array $position, ?array $nextStop): ?array
    {
        if (!$offRoute || $position === null || $nextStop === null) {
            return null;
        }

        return [$position, $nextStop];
    }
}
