<?php

declare(strict_types=1);

namespace Logistics\Planning\Domain\ValueObject;

/**
 * Salida completa del backend de optimización para un día: las rutas armadas (cada una con su camión, su
 * orden de reparto, ETA y geometría) y los pedidos que no se pudieron asignar (sin capacidad, sin camión
 * del departamento o sin ubicación).
 *
 * Reemplaza —con el mismo rol— a la vieja AutoPlanComposition cuando el ruteo lo resuelve un backend
 * (builtin Clarke-Wright+2-opt, osrm_trip o vroom): el runner la materializa en planes y en el resumen de
 * la corrida.
 */
final readonly class RoutingResult
{
    /**
     * @param list<PlannedRoute>      $routes
     * @param list<DeliveryCandidate> $unassigned
     */
    public function __construct(
        public array $routes,
        public array $unassigned,
    ) {}

    public function trucksPlanned(): int
    {
        return count($this->routes);
    }

    public function stopsPlanned(): int
    {
        $stops = 0;
        foreach ($this->routes as $route) {
            $stops += $route->stopCount();
        }

        return $stops;
    }

    public function unassignedCount(): int
    {
        return count($this->unassigned);
    }
}
