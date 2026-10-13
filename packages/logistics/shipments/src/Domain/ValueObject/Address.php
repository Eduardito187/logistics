<?php

declare(strict_types=1);

namespace Logistics\Shipments\Domain\ValueObject;

use Logistics\Shipments\Domain\Exception\ShipmentsException;

/**
 * Dirección de entrega: el texto libre tal como llega, una referencia opcional (indicaciones del
 * cliente) y, si se conoce, el punto geográfico. La geocodificación y el puntaje de confianza son
 * pasos aparte (ver AddressConfidenceScorer); aquí solo se guarda el dato.
 */
final readonly class Address
{
    private function __construct(
        public string $text,
        public ?string $reference,
        public ?GeoPoint $geoPoint,
    ) {}

    public static function of(string $text, ?string $reference = null, ?GeoPoint $geoPoint = null): self
    {
        $trimmedText = trim($text);
        if ($trimmedText === '') {
            throw ShipmentsException::because('la dirección no puede estar vacía');
        }

        $trimmedReference = $reference !== null ? trim($reference) : null;
        if ($trimmedReference === '') {
            $trimmedReference = null;
        }

        return new self($trimmedText, $trimmedReference, $geoPoint);
    }

    public function hasGeoPoint(): bool
    {
        return $this->geoPoint !== null;
    }

    /**
     * Devuelve la misma dirección con un punto geográfico asignado (al geocodificar).
     */
    public function withGeoPoint(GeoPoint $geoPoint): self
    {
        return new self($this->text, $this->reference, $geoPoint);
    }
}
