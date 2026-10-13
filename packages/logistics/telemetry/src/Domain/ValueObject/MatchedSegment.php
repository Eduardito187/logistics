<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Domain\ValueObject;

/**
 * Resultado del map-matching de una traza contra la red de calles: un tramo recorrido con su velocidad.
 *
 * Lo produce el puerto MapMatcher (impl OSRM en Infrastructure) y lo consume el worker de analítica de
 * calles, que lo acumula en el agregado StreetSegmentStats. Es un dato puro: no sabe de OSRM ni de HTTP.
 *
 * - `segmentKey`: clave estable del tramo (hash de los nodos OSRM que lo delimitan); permite acumular en
 *   el tiempo las muestras de la misma calle aunque provengan de vehículos y días distintos.
 * - `geometry`: polilínea del tramo como lista de puntos {lat,lng}.
 * - `speedKph`: velocidad observada en el tramo (km/h).
 * - `seconds`: segundos que tomó recorrer el tramo.
 * - `hour`: hora del día (0–23) en que se recorrió, para el histograma horario.
 */
final readonly class MatchedSegment
{
    /**
     * @param list<array{lat: float, lng: float}> $geometry
     */
    private function __construct(
        public string $segmentKey,
        public ?string $name,
        public array $geometry,
        public float $speedKph,
        public int $seconds,
        public int $hour,
    ) {}

    /**
     * @param list<array{lat: float, lng: float}> $geometry
     */
    public static function of(
        string $segmentKey,
        ?string $name,
        array $geometry,
        float $speedKph,
        int $seconds,
        int $hour,
    ): self {
        return new self($segmentKey, $name, array_values($geometry), $speedKph, $seconds, $hour);
    }
}
