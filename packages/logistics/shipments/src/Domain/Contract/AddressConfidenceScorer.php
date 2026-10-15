<?php

declare(strict_types=1);

namespace Logistics\Shipments\Domain\Contract;

use Logistics\Shipments\Domain\ValueObject\Address;
use Logistics\Shipments\Domain\ValueObject\AddressConfidence;

/**
 * Servicio de dominio que puntúa la confianza de una dirección. El dominio define QUÉ se necesita
 * (un score 0..100); la geocodificación real (Nominatim/OSM, heurística) es una decisión de
 * Infrastructure. Así el dominio no se acopla a ningún proveedor.
 */
interface AddressConfidenceScorer
{
    public function score(Address $address): AddressConfidence;
}
