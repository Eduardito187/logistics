<?php

declare(strict_types=1);

namespace Logistics\Planning\Domain\ValueObject;

use Logistics\Planning\Domain\Exception\PlanningException;

/**
 * Geometría de la ruta ya calculada por calles (polilínea GeoJSON LineString) con la distancia y la
 * duración totales del recorrido. Se calcula en el SERVIDOR al generar el plan y se PERSISTE con él, para
 * que el mapa la pinte tal cual sin depender del navegador ni volver a consultar el ruteador.
 *
 * Dato puro e inmutable. Se guarda y se transporta en forma primitiva (array), apto para columna jsonb.
 */
final readonly class RouteGeometry
{
    /**
     * @param list<array{float, float}> $coordinates pares [lng, lat] en orden del recorrido (formato GeoJSON)
     */
    private function __construct(
        public array $coordinates,
        public float $distanceMeters,
        public float $durationSeconds,
    ) {}

    /**
     * @param list<array{float, float}> $coordinates pares [lng, lat]
     */
    public static function of(array $coordinates, float $distanceMeters, float $durationSeconds): self
    {
        if (count($coordinates) < 2) {
            throw PlanningException::because('la geometría de la ruta necesita al menos dos puntos');
        }
        if ($distanceMeters < 0.0) {
            throw PlanningException::because("distancia total inválida: {$distanceMeters}");
        }
        if ($durationSeconds < 0.0) {
            throw PlanningException::because("duración total inválida: {$durationSeconds}");
        }

        return new self($coordinates, $distanceMeters, $durationSeconds);
    }

    /**
     * Reconstruye la geometría desde su forma primitiva persistida (columna jsonb), tolerando datos mal
     * formados devolviendo null (nunca rompe la lectura de un plan por una geometría corrupta).
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): ?self
    {
        $coordinates = $data['coordinates'] ?? null;
        $distance = $data['distance_meters'] ?? null;
        $duration = $data['duration_seconds'] ?? null;

        if (!is_array($coordinates) || !is_numeric($distance) || !is_numeric($duration)) {
            return null;
        }

        $points = [];
        foreach ($coordinates as $pair) {
            if (!is_array($pair) || !isset($pair[0], $pair[1]) || !is_numeric($pair[0]) || !is_numeric($pair[1])) {
                return null;
            }
            $points[] = [(float) $pair[0], (float) $pair[1]];
        }

        if (count($points) < 2) {
            return null;
        }

        return new self($points, (float) $distance, (float) $duration);
    }

    /**
     * Forma primitiva para persistir (jsonb) y exponer en APIs y mapas. Es un GeoJSON LineString más los
     * totales del recorrido.
     *
     * @return array{type: string, coordinates: list<array{float, float}>, distance_meters: float, duration_seconds: float}
     */
    public function toArray(): array
    {
        return [
            'type' => 'LineString',
            'coordinates' => $this->coordinates,
            'distance_meters' => $this->distanceMeters,
            'duration_seconds' => $this->durationSeconds,
        ];
    }
}
