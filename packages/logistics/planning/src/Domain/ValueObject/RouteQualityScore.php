<?php

declare(strict_types=1);

namespace Logistics\Planning\Domain\ValueObject;

use Logistics\Planning\Domain\Exception\PlanningException;

/**
 * Métricas de CALIDAD de una ruta (R-06): distancia total y tiempo estimado del recorrido, y la OCUPACIÓN
 * del camión (carga kg/m3 contra su capacidad). Dato puro e inmutable, derivable sin I/O a partir de la
 * geometría ya calculada del plan (o de la matriz) y de la carga/capacidad del camión.
 *
 * La ocupación es un ratio 0..1 (0.85 = 85 % lleno) y queda null cuando no se conoce la capacidad del
 * camión (no se puede calcular el porcentaje). Se guarda y transporta en forma primitiva (array) para
 * poder cachearlo sin serializar el objeto (regla 7 de la guía).
 */
final readonly class RouteQualityScore
{
    private function __construct(
        public float $distanceMeters,
        public float $durationSeconds,
        public float $loadKg,
        public float $loadM3,
        public ?float $capacityKg,
        public ?float $capacityM3,
        public ?float $occupancyKg,
        public ?float $occupancyM3,
    ) {}

    /**
     * Arma el score desde la distancia/tiempo ya calculados del recorrido y la carga/capacidad del camión.
     * La ocupación se calcula solo si la capacidad correspondiente es conocida y positiva; si no, queda
     * null (no se puede expresar el porcentaje). Un camión sobrecargado da una ocupación > 1 (se expone tal
     * cual para que el panel la marque, no se recorta).
     */
    public static function of(
        float $distanceMeters,
        float $durationSeconds,
        float $loadKg,
        float $loadM3,
        ?float $capacityKg = null,
        ?float $capacityM3 = null,
    ): self {
        if ($distanceMeters < 0.0) {
            throw PlanningException::because("distancia total inválida: {$distanceMeters}");
        }
        if ($durationSeconds < 0.0) {
            throw PlanningException::because("duración total inválida: {$durationSeconds}");
        }
        if ($loadKg < 0.0 || $loadM3 < 0.0) {
            throw PlanningException::because('la carga de la ruta no puede ser negativa');
        }

        return new self(
            $distanceMeters,
            $durationSeconds,
            $loadKg,
            $loadM3,
            $capacityKg,
            $capacityM3,
            self::ratio($loadKg, $capacityKg),
            self::ratio($loadM3, $capacityM3),
        );
    }

    /**
     * Ocupación como ratio carga/capacidad, o null si la capacidad no se conoce o no es positiva.
     */
    private static function ratio(float $load, ?float $capacity): ?float
    {
        if ($capacity === null || $capacity <= 0.0) {
            return null;
        }

        return $load / $capacity;
    }

    /**
     * Forma primitiva para persistir/cachear y exponer en APIs y paneles.
     *
     * @return array{distance_meters: float, duration_seconds: float, load_kg: float, load_m3: float, capacity_kg: float|null, capacity_m3: float|null, occupancy_kg: float|null, occupancy_m3: float|null}
     */
    public function toArray(): array
    {
        return [
            'distance_meters' => $this->distanceMeters,
            'duration_seconds' => $this->durationSeconds,
            'load_kg' => $this->loadKg,
            'load_m3' => $this->loadM3,
            'capacity_kg' => $this->capacityKg,
            'capacity_m3' => $this->capacityM3,
            'occupancy_kg' => $this->occupancyKg,
            'occupancy_m3' => $this->occupancyM3,
        ];
    }
}
