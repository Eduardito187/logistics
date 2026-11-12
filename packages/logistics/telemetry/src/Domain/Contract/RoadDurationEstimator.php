<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Domain\Contract;

use Logistics\Telemetry\Domain\ValueObject\GeoPoint;

/**
 * Puerto de duraciones viales entre puntos consecutivos (OSRM `route`, sin tráfico en vivo).
 *
 * Hace I/O externo: SOLO se usa desde el comando programado de ETAs, nunca dentro de un request. Devuelve null
 * cuando no hay backend configurado o falla: el llamador cae al respaldo en línea recta.
 */
interface RoadDurationEstimator
{
    /**
     * Un tramo por cada par consecutivo de `$waypoints` (n puntos → n-1 tramos), o null si no se pudo.
     *
     * @param list<GeoPoint> $waypoints
     *
     * @return list<array{distance_m: float, duration_s: float}>|null
     */
    public function legs(array $waypoints): ?array;
}
