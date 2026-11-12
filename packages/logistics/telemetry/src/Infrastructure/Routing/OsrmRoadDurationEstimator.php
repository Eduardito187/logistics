<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Infrastructure\Routing;

use Illuminate\Support\Facades\Http;
use Logistics\Telemetry\Domain\Contract\RoadDurationEstimator;
use Logistics\Telemetry\Domain\ValueObject\GeoPoint;
use Throwable;

/**
 * Duraciones viales por tramo con OSRM `route` (`/route/v1/driving/{lng,lat;…}?overview=false`).
 *
 * OSRM no tiene tráfico en vivo: las duraciones son las del perfil vial (velocidades por tipo de calle). Se usa
 * SOLO desde el comando programado `telemetry:refresh-etas` (nunca dentro de un request de usuario). Sin host
 * configurado (`logistics-telemetry.eta.osrm_host` vacío, el default de un docker nuevo) o ante cualquier fallo
 * devuelve null y el llamador cae al respaldo en línea recta.
 */
final readonly class OsrmRoadDurationEstimator implements RoadDurationEstimator
{
    /** Tope de puntos por consulta (límite por defecto del servidor OSRM público/propio). */
    private const int MAX_WAYPOINTS = 100;

    public function legs(array $waypoints): ?array
    {
        $host = rtrim((string) config('logistics-telemetry.eta.osrm_host', ''), '/');
        if ($host === '' || count($waypoints) < 2 || count($waypoints) > self::MAX_WAYPOINTS) {
            return null;
        }

        $coordinates = implode(';', array_map(
            static fn (GeoPoint $p): string => sprintf('%.6F,%.6F', $p->lng, $p->lat),
            $waypoints,
        ));

        try {
            $response = Http::timeout((int) config('logistics-telemetry.eta.osrm_timeout', 5))
                ->acceptJson()
                ->get("{$host}/route/v1/driving/{$coordinates}", ['overview' => 'false', 'steps' => 'false']);

            if (!$response->successful()) {
                return null;
            }

            $legs = $response->json('routes.0.legs');
        } catch (Throwable) {
            return null;
        }

        if (!is_array($legs) || count($legs) !== count($waypoints) - 1) {
            return null;
        }

        $result = [];
        foreach ($legs as $leg) {
            if (!is_array($leg) || !is_numeric($leg['distance'] ?? null) || !is_numeric($leg['duration'] ?? null)) {
                return null;
            }
            $result[] = ['distance_m' => (float) $leg['distance'], 'duration_s' => (float) $leg['duration']];
        }

        return $result;
    }
}
