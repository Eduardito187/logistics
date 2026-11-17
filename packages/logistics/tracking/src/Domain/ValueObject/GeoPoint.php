<?php

declare(strict_types=1);

namespace Logistics\Tracking\Domain\ValueObject;

use Logistics\Tracking\Domain\Exception\TrackingException;

/**
 * Punto geográfico (latitud/longitud) en WGS 84, el mismo marco que usa PostGIS (SRID 4326).
 *
 * En este contexto representa la posición en vivo del camión; el dominio solo guarda las coordenadas
 * validadas y la infraestructura decide cómo persistirlas.
 */
final readonly class GeoPoint
{
    private function __construct(
        public float $latitude,
        public float $longitude,
    ) {}

    public static function of(float $latitude, float $longitude): self
    {
        if ($latitude < -90.0 || $latitude > 90.0) {
            throw TrackingException::because("latitud fuera de rango (-90..90): {$latitude}");
        }
        if ($longitude < -180.0 || $longitude > 180.0) {
            throw TrackingException::because("longitud fuera de rango (-180..180): {$longitude}");
        }

        return new self($latitude, $longitude);
    }

    public function equals(self $other): bool
    {
        return $this->latitude === $other->latitude && $this->longitude === $other->longitude;
    }

    /**
     * Distancia geodésica a otro punto en metros (haversine). Sirve para derivar la velocidad entre dos
     * reportes seguidos cuando el dispositivo no la manda.
     */
    public function distanceMetersTo(self $other): float
    {
        $earthRadius = 6_371_000.0;
        $dLat = deg2rad($other->latitude - $this->latitude);
        $dLng = deg2rad($other->longitude - $this->longitude);
        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($this->latitude)) * cos(deg2rad($other->latitude)) * sin($dLng / 2) ** 2;

        return 2 * $earthRadius * asin(min(1.0, sqrt($a)));
    }

    /**
     * Rumbo inicial hacia otro punto en grados desde el norte (0..360), para derivar el heading entre dos
     * reportes seguidos cuando el dispositivo no lo manda.
     */
    public function bearingDegreesTo(self $other): float
    {
        $lat1 = deg2rad($this->latitude);
        $lat2 = deg2rad($other->latitude);
        $dLng = deg2rad($other->longitude - $this->longitude);

        $y = sin($dLng) * cos($lat2);
        $x = cos($lat1) * sin($lat2) - sin($lat1) * cos($lat2) * cos($dLng);

        return fmod(rad2deg(atan2($y, $x)) + 360.0, 360.0);
    }

    /**
     * @return array{lat: float, lng: float}
     */
    public function toArray(): array
    {
        return ['lat' => $this->latitude, 'lng' => $this->longitude];
    }
}
