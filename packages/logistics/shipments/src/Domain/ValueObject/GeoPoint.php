<?php

declare(strict_types=1);

namespace Logistics\Shipments\Domain\ValueObject;

use Logistics\Shipments\Domain\Exception\ShipmentsException;

/**
 * Coordenada geográfica validada (latitud/longitud en grados decimales, WGS84).
 *
 * El dato puede venir de la fuente o calcularse al geocodificar la dirección. Su sola presencia no
 * implica confianza alta: eso lo decide AddressConfidence.
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
            throw ShipmentsException::because("latitud fuera de rango (-90..90): {$lat}");
        }
        if ($lng < -180.0 || $lng > 180.0) {
            throw ShipmentsException::because("longitud fuera de rango (-180..180): {$lng}");
        }

        return new self($lat, $lng);
    }

    public function equals(self $other): bool
    {
        return $this->lat === $other->lat && $this->lng === $other->lng;
    }
}
