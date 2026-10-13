<?php

declare(strict_types=1);

namespace Logistics\Shipments\Infrastructure\Geocoding;

use Logistics\Shipments\Domain\Contract\AddressConfidenceScorer;
use Logistics\Shipments\Domain\ValueObject\Address;
use Logistics\Shipments\Domain\ValueObject\AddressConfidence;

/**
 * Implementación por defecto del scorer: devuelve una confianza media fija (50 → nivel Medium), sin
 * llamar a ningún proveedor externo.
 *
 * Deliberadamente neutra para no acoplar el contexto a un proveedor de geocodificación todavía. La
 * integración real (Google, etc.) llega después como otra implementación de AddressConfidenceScorer,
 * sin tocar el dominio.
 */
final class NullAddressConfidenceScorer implements AddressConfidenceScorer
{
    private const FIXED_SCORE = 50;

    public function score(Address $address): AddressConfidence
    {
        return AddressConfidence::of(self::FIXED_SCORE);
    }
}
