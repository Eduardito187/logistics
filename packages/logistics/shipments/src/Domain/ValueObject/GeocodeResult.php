<?php

declare(strict_types=1);

namespace Logistics\Shipments\Domain\ValueObject;

/**
 * Resultado de geocodificar una dirección: la confianza (score 0..100 + nivel), el tipo de coincidencia
 * y, si el proveedor la ubicó, el punto geográfico.
 *
 * Es lo que el contrato Geocoder devuelve al dominio. A diferencia de un score suelto, trae también la
 * coordenada para que la re-geocodificación pueda fijar el punto del envío, no solo recalcular su
 * confianza. El dominio nunca ve al proveedor: solo este resultado normalizado.
 */
final readonly class GeocodeResult
{
    private function __construct(
        public AddressConfidence $confidence,
        public GeocodeMatchType $matchType,
        public ?GeoPoint $geoPoint,
    ) {}

    public static function of(
        AddressConfidence $confidence,
        GeocodeMatchType $matchType,
        ?GeoPoint $geoPoint = null,
    ): self {
        return new self($confidence, $matchType, $geoPoint);
    }

    /**
     * Sin coincidencia: el proveedor no ubicó la dirección. No trae punto; la confianza la decide el
     * adaptador (típicamente baja) para que la dirección quede marcada como candidata a confirmar.
     */
    public static function notFound(AddressConfidence $confidence): self
    {
        return new self($confidence, GeocodeMatchType::None, null);
    }

    public function hasGeoPoint(): bool
    {
        return $this->geoPoint !== null;
    }
}
