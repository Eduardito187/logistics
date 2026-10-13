<?php

declare(strict_types=1);

namespace Logistics\Shipments\Infrastructure\Geocoding;

use Logistics\Shipments\Domain\Contract\Geocoder;
use Logistics\Shipments\Domain\ValueObject\Address;
use Logistics\Shipments\Domain\ValueObject\AddressConfidence;
use Logistics\Shipments\Domain\ValueObject\GeocodeMatchType;
use Logistics\Shipments\Domain\ValueObject\GeocodeResult;
use Logistics\Shipments\Domain\ValueObject\GeoPoint;

/**
 * Base de los adaptadores de geocodificación: deja a cada proveedor solo resolver geocode() y aporta lo
 * común: el score() del contrato legado (la confianza del resultado) y la (de)serialización a la forma
 * primitiva que se cachea (regla 7 del proyecto: se cachea el array, nunca el objeto de dominio).
 */
abstract class AbstractGeocoder implements Geocoder
{
    public function score(Address $address): AddressConfidence
    {
        return $this->geocode($address)->confidence;
    }

    /**
     * Texto de consulta: la dirección y, si existe, la referencia del cliente.
     */
    protected function queryText(Address $address): string
    {
        $reference = $address->reference;

        return $reference === null
            ? $address->text
            : $address->text.', '.$reference;
    }

    /**
     * Forma primitiva (array) de un resultado, para cachear sin serializar el objeto de dominio.
     *
     * @return array{score: int, match: string, lat: float|null, lng: float|null}
     */
    protected function toPrimitive(GeocodeResult $result): array
    {
        return [
            'score' => $result->confidence->score,
            'match' => $result->matchType->value,
            'lat' => $result->geoPoint?->lat,
            'lng' => $result->geoPoint?->lng,
        ];
    }

    /**
     * Reconstruye un resultado desde su forma primitiva cacheada; null si el array no tiene la forma
     * esperada (caché de una versión anterior, por ejemplo), para forzar un recálculo limpio.
     *
     * @param array<array-key, mixed> $primitive
     */
    protected function fromPrimitive(array $primitive): ?GeocodeResult
    {
        $score = $primitive['score'] ?? null;
        $match = $primitive['match'] ?? null;
        if (!is_int($score) || !is_string($match)) {
            return null;
        }

        $matchType = GeocodeMatchType::tryFrom($match);
        if ($matchType === null) {
            return null;
        }

        $lat = $primitive['lat'] ?? null;
        $lng = $primitive['lng'] ?? null;
        $geoPoint = is_numeric($lat) && is_numeric($lng)
            ? GeoPoint::of((float) $lat, (float) $lng)
            : null;

        return GeocodeResult::of(AddressConfidence::of($score), $matchType, $geoPoint);
    }
}
