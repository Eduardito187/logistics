<?php

declare(strict_types=1);

namespace Logistics\Planning\Application\ReadModel;

/**
 * Comparación de los backends de ruteo (builtin/osrm/vroom) para UN plan (R-06): qué distancia, tiempo y
 * costo produce cada backend sobre el mismo conjunto de paradas, y cuál es el mejor (menor distancia entre
 * los disponibles). Sirve para decidir con datos qué motor conviene, sin tocar el plan.
 */
final readonly class RoutingBackendComparisonView
{
    /**
     * @param list<RoutingBackendStatView> $backends
     */
    public function __construct(
        public string $planId,
        public int $stopCount,
        public array $backends,
    ) {}

    /**
     * Nombre del backend disponible con MENOR distancia total, o null si ninguno respondió.
     */
    public function bestBackend(): ?string
    {
        $best = null;
        $bestDistance = INF;
        foreach ($this->backends as $stat) {
            if ($stat->available && $stat->distanceMeters < $bestDistance) {
                $bestDistance = $stat->distanceMeters;
                $best = $stat->backend;
            }
        }

        return $best;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'plan_id' => $this->planId,
            'stop_count' => $this->stopCount,
            'best_backend' => $this->bestBackend(),
            'backends' => array_map(
                static fn (RoutingBackendStatView $stat): array => $stat->toArray(),
                $this->backends,
            ),
        ];
    }
}
