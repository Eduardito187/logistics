<?php

declare(strict_types=1);

namespace Logistics\Execution\Infrastructure\Routing;

use Illuminate\Http\Client\Factory as HttpClient;
use Throwable;

/**
 * Calcula la GEOMETRÍA de la ruta por CALLES (OSRM /route, overview=full, geometries=geojson) a partir de
 * las coordenadas de las paradas en orden. Vive en Ejecución para que la ruta del chofer SIEMPRE se dibuje
 * por las calles aunque no exista un plan con geometría persistida: nunca una línea recta que atraviese el
 * mapa de punta a punta cuando el ruteador está disponible.
 *
 * Entra y sale en PRIMITIVOS (pares [lng, lat]); no acopla Ejecución al dominio de Planificación. Tolerante:
 * ante cualquier fallo del ruteador (host vacío, timeout, respuesta no-Ok, cuerpo mal formado) devuelve null
 * y el lector decide el último recurso (la recta entre paradas). Nunca lanza.
 *
 * @phpstan-type StreetGeometry array{type: string, coordinates: list<array{float, float}>, distance_meters: float, duration_seconds: float}
 */
final readonly class OsrmStreetGeometry
{
    public function __construct(
        private HttpClient $http,
        private string $baseUrl,
        private int $timeout,
        private string $profile = 'driving',
    ) {}

    /**
     * Geometría por calles (GeoJSON LineString + distancia/duración) del recorrido que pasa por los puntos en
     * orden, o null si no se pudo calcular (menos de dos puntos, OSRM apagado/no disponible o respuesta inválida).
     *
     * @param  list<array{0: float, 1: float}> $points pares [lng, lat] en orden de reparto
     * @return StreetGeometry|null
     */
    public function forPoints(array $points): ?array
    {
        if (count($points) < 2 || trim($this->baseUrl) === '') {
            return null;
        }

        try {
            return $this->fromOsrm($points);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  list<array{0: float, 1: float}> $points
     * @return StreetGeometry|null
     */
    private function fromOsrm(array $points): ?array
    {
        $path = implode(';', array_map(
            static fn (array $p): string => $p[0].','.$p[1],
            $points,
        ));

        $url = rtrim($this->baseUrl, '/')."/route/v1/{$this->profile}/{$path}";

        $response = $this->http->timeout($this->timeout)
            ->connectTimeout($this->timeout)
            ->get($url, [
                'overview' => 'full',
                'geometries' => 'geojson',
                'steps' => 'false',
                'annotations' => 'false',
            ]);

        if (!$response->ok()) {
            return null;
        }

        /** @var mixed $body */
        $body = $response->json();
        if (!is_array($body) || ($body['code'] ?? null) !== 'Ok') {
            return null;
        }

        $route = $body['routes'][0] ?? null;
        if (!is_array($route)) {
            return null;
        }

        $rawCoordinates = $route['geometry']['coordinates'] ?? null;
        $distance = $route['distance'] ?? null;
        $duration = $route['duration'] ?? null;
        if (!is_array($rawCoordinates) || !is_numeric($distance) || !is_numeric($duration)) {
            return null;
        }

        $coordinates = [];
        foreach ($rawCoordinates as $pair) {
            if (!is_array($pair) || !isset($pair[0], $pair[1]) || !is_numeric($pair[0]) || !is_numeric($pair[1])) {
                continue;
            }
            $coordinates[] = [(float) $pair[0], (float) $pair[1]];
        }
        if (count($coordinates) < 2) {
            return null;
        }

        return [
            'type' => 'LineString',
            'coordinates' => $coordinates,
            'distance_meters' => round((float) $distance, 1),
            'duration_seconds' => round((float) $duration, 1),
        ];
    }
}
