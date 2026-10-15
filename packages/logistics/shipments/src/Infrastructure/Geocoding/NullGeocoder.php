<?php

declare(strict_types=1);

namespace Logistics\Shipments\Infrastructure\Geocoding;

use Logistics\Shipments\Domain\ValueObject\Address;
use Logistics\Shipments\Domain\ValueObject\AddressConfidence;
use Logistics\Shipments\Domain\ValueObject\GeocodeMatchType;
use Logistics\Shipments\Domain\ValueObject\GeocodeResult;

/**
 * Geocodificador neutro (stub): no llama a ningún proveedor. Devuelve una confianza media fija y sin
 * coordenada, para que la app arranque y se pueda desarrollar sin credenciales ni acceso a internet.
 *
 * Es el fallback cuando Nominatim está deshabilitado (entornos sin servicio ni salida a internet;
 * ADR: I/O externo configurable y degradable). La integración real llega como otro Geocoder, sin
 * tocar el dominio.
 */
final class NullGeocoder extends AbstractGeocoder
{
    /** Confianza media por defecto (50 → nivel Medium): ni se descarta ni se da por buena a ciegas. */
    private const DEFAULT_SCORE = 50;

    public function __construct(
        private readonly int $score = self::DEFAULT_SCORE,
    ) {}

    public function geocode(Address $address): GeocodeResult
    {
        return GeocodeResult::of(
            AddressConfidence::of($this->score),
            GeocodeMatchType::None,
            $address->geoPoint,
        );
    }
}
