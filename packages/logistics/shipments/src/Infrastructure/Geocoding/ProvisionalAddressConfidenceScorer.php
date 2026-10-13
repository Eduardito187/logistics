<?php

declare(strict_types=1);

namespace Logistics\Shipments\Infrastructure\Geocoding;

use Logistics\Shipments\Domain\Contract\AddressConfidenceScorer;
use Logistics\Shipments\Domain\ValueObject\Address;
use Logistics\Shipments\Domain\ValueObject\AddressConfidence;

/**
 * Scorer provisional, LOCAL y sin I/O, para el momento del ingreso del pedido.
 *
 * El ingreso de pedidos corre dentro de un request/transacción de usuario, donde la plataforma no hace
 * HTTP síncrono a terceros (regla del proyecto: todo I/O externo pasa por outbox). Por eso al recibir
 * NO se llama al geocodificador real: se asigna una confianza provisional por heurística —si la fuente
 * trajo coordenadas, se confía más; si no, queda baja y candidata a geocodificar— y la geocodificación
 * real (Google/Nominatim) la hace después, asíncrona, el comando GeocodeShipmentAddress vía el worker.
 *
 * Los dos umbrales son configurables (geocoder.provisional).
 */
final class ProvisionalAddressConfidenceScorer implements AddressConfidenceScorer
{
    /** Confianza por defecto cuando la fuente ya trajo coordenada (alta, pero sin verificar). */
    private const DEFAULT_WITH_COORDINATES = 70;

    /** Confianza por defecto cuando no hay coordenada (baja: necesita geocodificación/confirmación). */
    private const DEFAULT_WITHOUT_COORDINATES = 30;

    public function __construct(
        private readonly int $withCoordinates = self::DEFAULT_WITH_COORDINATES,
        private readonly int $withoutCoordinates = self::DEFAULT_WITHOUT_COORDINATES,
    ) {}

    public function score(Address $address): AddressConfidence
    {
        return AddressConfidence::of(
            $address->hasGeoPoint()
                ? $this->clamp($this->withCoordinates)
                : $this->clamp($this->withoutCoordinates),
        );
    }

    private function clamp(int $score): int
    {
        return max(0, min(100, $score));
    }
}
