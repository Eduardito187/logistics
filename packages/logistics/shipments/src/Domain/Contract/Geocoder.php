<?php

declare(strict_types=1);

namespace Logistics\Shipments\Domain\Contract;

use Logistics\Shipments\Domain\ValueObject\Address;
use Logistics\Shipments\Domain\ValueObject\GeocodeResult;

/**
 * Geocodificador de direcciones: resuelve una dirección a un punto geográfico más su confianza y tipo
 * de coincidencia (ver GeocodeResult). El dominio define QUÉ necesita; el CÓMO (Nominatim/OSM,
 * heurística) es decisión de Infrastructure, así el dominio no se acopla a ningún proveedor.
 *
 * Extiende AddressConfidenceScorer: todo geocodificador sabe, además, puntuar una dirección (el score
 * es la confianza del resultado). El ingreso de pedidos solo necesita el score; la re-geocodificación
 * necesita el resultado completo para fijar también la coordenada.
 */
interface Geocoder extends AddressConfidenceScorer
{
    public function geocode(Address $address): GeocodeResult;
}
