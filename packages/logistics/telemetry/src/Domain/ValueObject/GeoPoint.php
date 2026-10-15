<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Domain\ValueObject;

use Logistics\Telemetry\Domain\Exception\TelemetryException;

/**
 * Punto geográfico (lat/lng) de una lectura de telemetría. Propio del contexto (aislamiento DDD): no se
 * importa el GeoPoint de otro bounded context.
 *
 * El repo guarda coordenadas como decimal(10,7) (no PostGIS), así que acá solo se validan rangos y se
 * ofrece la distancia haversine en metros, que es lo que necesitan trips (distancia recorrida) y la
 * analítica de calles/geocercas sin depender de la base.
 */
final readonly class GeoPoint
{
    private const EARTH_RADIUS_METERS = 6_371_000.0;

    private function __construct(
        public float $lat,
        public float $lng,
    ) {}

    public static function of(float $lat, float $lng): self
    {
        if ($lat < -90.0 || $lat > 90.0) {
            throw TelemetryException::because("Latitud fuera de rango: {$lat}");
        }
        if ($lng < -180.0 || $lng > 180.0) {
            throw TelemetryException::because("Longitud fuera de rango: {$lng}");
        }

        return new self($lat, $lng);
    }

    /**
     * Distancia en metros hasta otro punto por la fórmula del haversine (esfera). Suficiente para
     * acumular kilometraje de un trip y medir desplazamientos entre pings.
     */
    public function distanceMetersTo(self $other): float
    {
        $latFrom = deg2rad($this->lat);
        $latTo = deg2rad($other->lat);
        $latDelta = deg2rad($other->lat - $this->lat);
        $lngDelta = deg2rad($other->lng - $this->lng);

        $a = sin($latDelta / 2) ** 2
            + cos($latFrom) * cos($latTo) * sin($lngDelta / 2) ** 2;

        return self::EARTH_RADIUS_METERS * 2 * asin(min(1.0, sqrt($a)));
    }

    public function equals(self $other): bool
    {
        return abs($this->lat - $other->lat) < 1e-9 && abs($this->lng - $other->lng) < 1e-9;
    }

    /**
     * @return array{lat: float, lng: float}
     */
    public function toArray(): array
    {
        return ['lat' => $this->lat, 'lng' => $this->lng];
    }
}
