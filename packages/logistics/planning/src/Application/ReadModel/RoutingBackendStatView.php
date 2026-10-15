<?php

declare(strict_types=1);

namespace Logistics\Planning\Application\ReadModel;

/**
 * Resultado de UN backend de ruteo (builtin/osrm/vroom) al resolver el mismo conjunto de paradas de un
 * plan (R-06): si estaba disponible, la distancia y el tiempo totales que produjo, cuántas paradas ruteó y
 * cuántas dejó sin asignar, y el costo estimado. Forma plana en primitivos.
 *
 * `available` en false = el backend no respondió o no está configurado (p. ej. OSRM/VROOM caído): sus
 * métricas quedan en 0 y no debe compararse contra los que sí respondieron.
 */
final readonly class RoutingBackendStatView
{
    public function __construct(
        public string $backend,
        public bool $available,
        public float $distanceMeters,
        public float $durationSeconds,
        public int $stopsRouted,
        public int $unassigned,
        public int $costCents,
        public string $currency,
    ) {}

    /**
     * Resultado "no disponible" para un backend que no respondió.
     */
    public static function unavailable(string $backend, string $currency): self
    {
        return new self($backend, false, 0.0, 0.0, 0, 0, 0, $currency);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'backend' => $this->backend,
            'available' => $this->available,
            'distance_meters' => $this->distanceMeters,
            'distance_km' => round($this->distanceMeters / 1000, 2),
            'duration_seconds' => $this->durationSeconds,
            'duration_minutes' => (int) round($this->durationSeconds / 60),
            'stops_routed' => $this->stopsRouted,
            'unassigned' => $this->unassigned,
            'cost_cents' => $this->costCents,
            'currency' => $this->currency,
        ];
    }
}
