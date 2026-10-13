<?php

declare(strict_types=1);

namespace Logistics\Coverage\Domain\ValueObject;

use Logistics\Coverage\Domain\Exception\CoverageException;

/**
 * Un punto geográfico en coordenadas WGS84 (EPSG:4326).
 *
 * Guarda latitud y longitud ya validadas: resolver "este punto, ¿en qué zona cae?" empieza acá.
 * El orden de los campos es (lat, lng) como lo escribe una persona; GeoJSON usa el orden inverso
 * (lng, lat), y la conversión se hace explícita donde haga falta (ver toGeoJsonCoordinates()).
 */
final readonly class GeoPoint
{
    private function __construct(
        public float $lat,
        public float $lng,
    ) {}

    public static function of(float $lat, float $lng): self
    {
        if ($lat < -90.0 || $lat > 90.0) {
            throw CoverageException::because("latitud fuera de rango (-90..90): {$lat}");
        }
        if ($lng < -180.0 || $lng > 180.0) {
            throw CoverageException::because("longitud fuera de rango (-180..180): {$lng}");
        }

        return new self($lat, $lng);
    }

    public function equals(self $other): bool
    {
        return $this->lat === $other->lat && $this->lng === $other->lng;
    }

    /**
     * Coordenada en orden GeoJSON: [longitud, latitud].
     *
     * @return array{0: float, 1: float}
     */
    public function toGeoJsonCoordinates(): array
    {
        return [$this->lng, $this->lat];
    }
}
