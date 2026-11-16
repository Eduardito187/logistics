<?php

declare(strict_types=1);

namespace Logistics\Execution\Application\Query\GetDriverRoute;

use Logistics\Execution\Application\Contract\LiveRouteStateReader;
use Logistics\Execution\Application\Contract\RouteGeometryReader;
use Logistics\Execution\Application\Contract\StopEnrichmentReader;
use Logistics\Execution\Application\ReadModel\DriverRouteView;
use Logistics\Execution\Application\Service\StaleRouteStops;
use Logistics\Execution\Domain\Contract\VehicleRouteRepository;
use Logistics\Execution\Domain\Exception\ExecutionException;
use Logistics\Execution\Domain\Model\VehicleRoute;
use Logistics\Execution\Domain\ValueObject\RouteDate;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;

/**
 * @implements QueryHandler<GetDriverRoute, ?DriverRouteView>
 */
final class GetDriverRouteHandler implements QueryHandler
{
    public function __construct(
        private readonly VehicleRouteRepository $routes,
        private readonly StopEnrichmentReader $stopEnrichment,
        private readonly RouteGeometryReader $routeGeometry,
        private readonly StaleRouteStops $staleStops,
        private readonly LiveRouteStateReader $liveState,
    ) {}

    public function handle(Query $query): ?DriverRouteView
    {
        if (!$query instanceof GetDriverRoute) {
            throw ExecutionException::because('GetDriverRouteHandler solo maneja GetDriverRoute');
        }

        $route = $query->routeDate === null
            ? $this->routes->findOpenByVehicleId($query->vehicleId)
            : $this->routes->findByVehicleAndDate($query->vehicleId, RouteDate::fromString($query->routeDate));

        if ($route === null) {
            return null;
        }

        // Lectura defensiva: aunque la ruta tenga filas viejas, no se devuelven paradas de envíos que ya
        // estaban cerrados antes del día de la ruta, y los contadores («parada N de M») cuentan solo las
        // paradas reales. La copia cargada no se persiste.
        $route = $this->staleStops->hideFrom($route);

        // Enriquecimiento cross-context (shipments/planning) por lectura directa, fuera del dominio:
        // teléfono + referencia + líneas de cada parada (en lote) y la geometría de la ruta del plan.
        $enrichment = $this->stopEnrichment->forShipmentIds($this->shipmentIdsOf($route));
        $geometry = $this->routeGeometry->forVehicleAndDate($route->vehicleId(), $route->date()->toString());

        // Estado en vivo del motor único de ETA: ETA fresca por parada y "fuera de ruta". Null si el motor
        // no está disponible; entonces cada parada conserva su ETA planificada.
        $live = $this->liveState->forVehicle($route->vehicleId());

        return DriverRouteView::fromRoute($route, $enrichment, $geometry, $live);
    }

    /**
     * Ids de envío de todas las paradas de la ruta, para resolver el enriquecimiento en una sola consulta.
     *
     * @return list<string>
     */
    private function shipmentIdsOf(VehicleRoute $route): array
    {
        $ids = [];
        foreach ($route->stops() as $stop) {
            $ids[] = $stop->shipmentId();
        }

        return $ids;
    }
}
