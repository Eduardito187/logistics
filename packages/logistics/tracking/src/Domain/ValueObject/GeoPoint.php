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
     * @return array{lat: float, lng: float}
     */
    public function toArray(): array
    {
        return ['lat' => $this->latitude, 'lng' => $this->longitude];
    }
}
