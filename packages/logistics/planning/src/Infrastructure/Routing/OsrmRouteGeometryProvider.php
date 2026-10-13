<?php

declare(strict_types=1);

namespace Logistics\Planning\Infrastructure\Routing;

use Illuminate\Http\Client\Factory as HttpClient;
use Logistics\Planning\Domain\Contract\RouteGeometryProvider;
use Logistics\Planning\Domain\ValueObject\GeoPoint;
use Logistics\Planning\Domain\ValueObject\RouteGeometry;
use Throwable;

/**
 * Calcula la GEOMETRÍA de la ruta por calles sobre OSRM (servicio /route con overview=full y
 * geometries=geojson) para los puntos en el ORDEN final de reparto. Vive en Infrastructure: el dominio
 * solo ve el contrato RouteGeometryProvider.
 *
 * La geometría se calcula en el SERVIDOR al generar/publicar el plan y se persiste con él, de modo que el
 * mapa la pinte tal cual sin depender del navegador (hoy frágil). Si OSRM no responde, degrada a la LÍNEA
 * RECTA entre las paradas (polilínea de los propios puntos) con distancia haversine y tiempo estimado, para
 * que el plan siempre tenga una geometría que mostrar.
 */
final readonly class OsrmRouteGeometryProvider implements RouteGeometryProvider
{
    public function __construct(
        private HttpClient $http,
        private string $baseUrl,
        private int $timeout,
        private float $fallbackSpeedKmh,
        private string $profile = 'driving',
    ) {}

    public function geometryFor(array $orderedPoints): ?RouteGeometry
    {
        $points = array_values($orderedPoints);
        if (count($points) < 2) {
            return null;
        }

        try {
            $geometry = $this->fromOsrm($points);
            if ($geometry !== null) {
                return $geometry;
            }
        } catch (Throwable) {
            // Cae a la línea recta.
        }

        return $this->straightLine($points);
    }

    /**
     * @param list<GeoPoint> $points
     */
    private function fromOsrm(array $points): ?RouteGeometry
    {
        $path = implode(';', array_map(
            static fn (GeoPoint $p): string => $p->longitude.','.$p->latitude,
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

        $coordinates = $route['geometry']['coordinates'] ?? null;
        $distance = $route['distance'] ?? null;
        $duration = $route['duration'] ?? null;
        if (!is_array($coordinates) || !is_numeric($distance) || !is_numeric($duration)) {
            return null;
        }

        $parsed = $this->parseCoordinates($coordinates);
        if (count($parsed) < 2) {
            return null;
        }

        return RouteGeometry::of($parsed, (float) $distance, (float) $duration);
    }

    /**
     * Degradación: la polilínea es la propia secuencia de paradas (línea recta) con distancia haversine y
     * tiempo estimado por velocidad media.
     *
     * @param list<GeoPoint> $points
     */
    private function straightLine(array $points): RouteGeometry
    {
        $coordinates = [];
        $distance = 0.0;
        $previous = null;
        foreach ($points as $point) {
            $coordinates[] = [$point->longitude, $point->latitude];
            if ($previous !== null) {
                $distance += $previous->haversineMeters($point);
            }
            $previous = $point;
        }

        $speedMetersPerSecond = max(1.0, $this->fallbackSpeedKmh) * 1000.0 / 3600.0;

        return RouteGeometry::of($coordinates, $distance, $distance / $speedMetersPerSecond);
    }

    /**
     * @param  array<int|string, mixed>  $coordinates
     * @return list<array{float, float}>
     */
    private function parseCoordinates(array $coordinates): array
    {
        $parsed = [];
        foreach ($coordinates as $pair) {
            if (!is_array($pair) || !isset($pair[0], $pair[1]) || !is_numeric($pair[0]) || !is_numeric($pair[1])) {
                continue;
            }
            $parsed[] = [(float) $pair[0], (float) $pair[1]];
        }

        return $parsed;
    }
}
