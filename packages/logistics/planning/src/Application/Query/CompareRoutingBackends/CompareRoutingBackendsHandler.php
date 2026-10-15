<?php

declare(strict_types=1);

namespace Logistics\Planning\Application\Query\CompareRoutingBackends;

use DateTimeImmutable;
use Exception;
use Logistics\Planning\Application\ReadModel\RoutingBackendComparisonView;
use Logistics\Planning\Application\ReadModel\RoutingBackendStatView;
use Logistics\Planning\Domain\Contract\AutoPlanSettingsProvider;
use Logistics\Planning\Domain\Contract\RouteComparisonProblemProvider;
use Logistics\Planning\Domain\Contract\RoutingBackend;
use Logistics\Planning\Domain\Exception\PlanningException;
use Logistics\Planning\Domain\ValueObject\PlanId;
use Logistics\Planning\Domain\ValueObject\PlannedRoute;
use Logistics\Planning\Domain\ValueObject\PlanStrategy;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;
use Throwable;

/**
 * Compara los backends de ruteo para un plan (R-06): reconstruye el problema del plan (origen + paradas +
 * camión) y corre CADA backend disponible sobre el MISMO conjunto, midiendo distancia, tiempo y costo. Un
 * backend que no responde (OSRM/VROOM caído o sin configurar) se reporta como no disponible, sin romper la
 * comparación. Devuelve null si el plan no existe o no se pudo reconstruir.
 *
 * La distancia/tiempo de cada backend salen de la geometría que ese backend calculó para sus rutas; el
 * costo es la distancia por un costo/km configurable. No modifica nada: es análisis de solo lectura.
 *
 * @implements QueryHandler<CompareRoutingBackends, ?RoutingBackendComparisonView>
 */
final readonly class CompareRoutingBackendsHandler implements QueryHandler
{
    /**
     * @param array<string, RoutingBackend> $backends backends nombrados a comparar (builtin/osrm/vroom)
     */
    public function __construct(
        private RouteComparisonProblemProvider $problems,
        private AutoPlanSettingsProvider $settings,
        private array $backends,
        private int $costPerKmCents,
        private string $currency,
    ) {}

    public function handle(Query $query): ?RoutingBackendComparisonView
    {
        if (!$query instanceof CompareRoutingBackends) {
            throw PlanningException::because('CompareRoutingBackendsHandler solo maneja CompareRoutingBackends');
        }

        $problem = $this->problems->forPlan(PlanId::fromString($query->planId));
        if ($problem === null) {
            return null;
        }

        // Parámetros de la corrida (hora de salida, holgura, etc.) desde settings, para la fecha del plan.
        $params = $this->settings->parametersFor($problem->date, PlanStrategy::Optimization, []);
        $departAt = $this->parseDepartAt($params->departAt);

        $stats = [];
        foreach ($this->backends as $name => $backend) {
            try {
                $result = $backend->route($departAt, $problem->origin, $problem->candidates, [$problem->vehicle], $params);
                $stats[] = $this->statFrom($name, $result->routes, $result->unassignedCount());
            } catch (Throwable) {
                // Backend no disponible (servidor caído/sin configurar): se reporta sin romper la comparación.
                $stats[] = RoutingBackendStatView::unavailable($name, $this->currency);
            }
        }

        return new RoutingBackendComparisonView($query->planId, $problem->stopCount(), $stats);
    }

    /**
     * Resume las rutas que produjo un backend en una fila de comparación: suma distancia/tiempo de las
     * geometrías y calcula el costo por la tarifa por km.
     *
     * @param list<PlannedRoute> $routes
     */
    private function statFrom(string $backend, array $routes, int $unassigned): RoutingBackendStatView
    {
        $distance = 0.0;
        $duration = 0.0;
        $stops = 0;

        foreach ($routes as $route) {
            $geometry = $route->geometry;
            if ($geometry !== null) {
                $distance += $geometry->distanceMeters;
                $duration += $geometry->durationSeconds;
            }
            $stops += $route->stopCount();
        }

        $costCents = (int) round($distance / 1000 * $this->costPerKmCents);

        return new RoutingBackendStatView($backend, true, $distance, $duration, $stops, $unassigned, $costCents, $this->currency);
    }

    private function parseDepartAt(string $departAt): DateTimeImmutable
    {
        try {
            return new DateTimeImmutable($departAt);
        } catch (Exception) {
            throw PlanningException::because("hora de salida inválida: {$departAt}");
        }
    }
}
