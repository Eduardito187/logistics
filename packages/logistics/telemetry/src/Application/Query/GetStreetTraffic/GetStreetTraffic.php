<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Application\Query\GetStreetTraffic;

use Logistics\Shared\Application\Query\Query;

/**
 * Pide el tráfico agregado de calles para pintar el mapa de calles transitadas vs libres.
 *
 * `bbox` acota el resultado a la zona visible del mapa (el mapa no carga el país entero). `null` = sin recorte (país completo).
 */
final readonly class GetStreetTraffic implements Query
{
    /**
     * @param ?array{min_lat: float, min_lng: float, max_lat: float, max_lng: float} $bbox
     */
    public function __construct(
        public ?array $bbox = null,
    ) {}
}
