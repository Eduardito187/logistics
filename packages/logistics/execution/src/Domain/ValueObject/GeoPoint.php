<?php

declare(strict_types=1);

namespace Logistics\Execution\Domain\ValueObject;

use Logistics\Execution\Domain\Exception\ExecutionException;

/**
 * Coordenada geográfica validada (latitud/longitud en grados decimales, WGS84) del lugar del escaneo.
 *
 * Cada escaneo en terreno trae su GPS: es parte de la evidencia inmutable del hecho.
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
            throw ExecutionException::because("latitud fuera de rango (-90..90): {$lat}");
        }
        if ($lng < -180.0 || $lng > 180.0) {
            throw ExecutionException::because("longitud fuera de rango (-180..180): {$lng}");
        }

        return new self($lat, $lng);
    }

    public function equals(self $other): bool
    {
        return $this->lat === $other->lat && $this->lng === $other->lng;
    }
}
