<?php

declare(strict_types=1);

namespace Logistics\Coverage\Domain\ValueObject;

use Logistics\Coverage\Domain\Exception\CoverageException;

/**
 * Anillo poligonal cerrado en coordenadas GeoJSON (orden [longitud, latitud], EPSG:4326).
 *
 * Modela el anillo exterior de una zona de cobertura. GeoJSON exige que un anillo lineal tenga al
 * menos 4 posiciones y que la primera y la última coincidan (polígono cerrado); ambas reglas se
 * validan al construir, así ninguna geometría inválida llega a PostGIS.
 *
 * No modela huecos (anillos interiores): una zona de cobertura es una superficie simple.
 */
final readonly class Polygon
{
    /**
     * @param array<int, array{0: float, 1: float}> $ring anillo exterior, ya normalizado a float y cerrado
     */
    private function __construct(public array $ring) {}

    /**
     * Construye desde un anillo de coordenadas GeoJSON [lng, lat].
     *
     * @param array<int, array{0: float|int, 1: float|int}> $ring
     */
    public static function fromRing(array $ring): self
    {
        if (count($ring) < 4) {
            throw CoverageException::because('un anillo necesita al menos 4 puntos: '.count($ring));
        }

        $normalized = [];
        foreach (array_values($ring) as $position) {
            $normalized[] = [(float) $position[0], (float) $position[1]];
        }

        $first = $normalized[array_key_first($normalized)];
        $last = $normalized[array_key_last($normalized)];
        if ($first[0] !== $last[0] || $first[1] !== $last[1]) {
            throw CoverageException::because('el anillo no está cerrado: el primer y el último punto deben coincidir');
        }

        return new self($normalized);
    }

    /**
     * Geometría GeoJSON lista para ST_GeomFromGeoJSON: {"type":"Polygon","coordinates":[[...]]}.
     *
     * @return array{type: string, coordinates: array<int, array<int, array{0: float, 1: float}>>}
     */
    public function toGeoJson(): array
    {
        return [
            'type' => 'Polygon',
            'coordinates' => [$this->ring],
        ];
    }
}
