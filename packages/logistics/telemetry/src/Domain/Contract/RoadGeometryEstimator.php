<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Domain\Contract;

use Logistics\Telemetry\Domain\ValueObject\GeoPoint;

/**
 * Puerto de la GEOMETRÍA vial entre dos puntos (OSRM `route` con geometría, sin tráfico en vivo). Es el mismo
 * backend de ruteo que usa la ETA (mismo host OSRM); aquí se pide la polilínea por calles, no la duración.
 *
 * Hace I/O externo: SOLO se usa desde el comando programado de ETAs, nunca dentro de un request. Devuelve null
 * cuando no hay backend configurado o falla: el llamador cae al respaldo en línea recta (los dos extremos).
 */
interface RoadGeometryEstimator
{
    /**
     * Polilínea por calles desde `$from` hasta `$to`, como lista de puntos `{lat, lng}`, o null si no se pudo.
     *
     * @return list<array{lat: float, lng: float}>|null
     */
    public function geometry(GeoPoint $from, GeoPoint $to): ?array;
}
