<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Infrastructure\Routing;

use Illuminate\Support\Facades\Http;
use Logistics\Telemetry\Domain\Contract\RoadGeometryEstimator;
use Logistics\Telemetry\Domain\ValueObject\GeoPoint;
use Throwable;

/**
 * Geometría vial entre dos puntos con OSRM `route`
 * (`/route/v1/driving/{lng,lat;lng,lat}?overview=full&geometries=geojson`).
 *
 * OSRM no tiene tráfico en vivo: la polilínea es la del perfil vial. Se usa SOLO desde el comando programado
 * `telemetry:refresh-etas` (nunca dentro de un request). Reutiliza el MISMO host OSRM que las duraciones de
 * ETA (`logistics-telemetry.eta.osrm_host`). Sin host configurado (default de un docker nuevo) o ante cualquier
 * fallo devuelve null y el llamador cae al respaldo en línea recta.
 */
final readonly class OsrmRoadGeometryEstimator implements RoadGeometryEstimator
{
    public function geometry(GeoPoint $from, GeoPoint $to): ?array
    {
        $host = rtrim((string) config('logistics-telemetry.eta.osrm_host', ''), '/');
        if ($host === '') {
            return null;
        }

        // OSRM espera lng,lat por punto.
        $coordinates = sprintf('%.6F,%.6F;%.6F,%.6F', $from->lng, $from->lat, $to->lng, $to->lat);

        try {
            $response = Http::timeout((int) config('logistics-telemetry.eta.osrm_timeout', 5))
                ->acceptJson()
                ->get("{$host}/route/v1/driving/{$coordinates}", [
                    'overview' => 'full',
                    'geometries' => 'geojson',
                    'steps' => 'false',
                    'annotations' => 'false',
                ]);

            if (!$response->successful()) {
                return null;
            }

            $points = $this->parse($response->json('routes.0.geometry.coordinates'));
        } catch (Throwable) {
            return null;
        }

        return count($points) >= 2 ? $points : null;
    }

    /**
     * Convierte las coordenadas GeoJSON de OSRM ([lng, lat], ...) a la lista de puntos `{lat, lng}`.
     *
     * @return list<array{lat: float, lng: float}>
     */
    private function parse(mixed $coordinates): array
    {
        if (!is_array($coordinates)) {
            return [];
        }

        $points = [];
        foreach ($coordinates as $pair) {
            if (!is_array($pair) || !is_numeric($pair[0] ?? null) || !is_numeric($pair[1] ?? null)) {
                continue;
            }
            $points[] = ['lat' => (float) $pair[1], 'lng' => (float) $pair[0]];
        }

        return $points;
    }
}
