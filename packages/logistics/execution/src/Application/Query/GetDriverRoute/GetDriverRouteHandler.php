<?php

declare(strict_types=1);

namespace Logistics\Execution\Application\Query\GetDriverRoute;

use Logistics\Execution\Application\ReadModel\DriverRouteView;
use Logistics\Execution\Domain\Contract\VehicleRouteRepository;
use Logistics\Execution\Domain\Exception\ExecutionException;
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
    ) {}

    public function handle(Query $query): ?DriverRouteView
    {
        if (!$query instanceof GetDriverRoute) {
            throw ExecutionException::because('GetDriverRouteHandler solo maneja GetDriverRoute');
        }

        $route = $query->routeDate === null
            ? $this->routes->findOpenByVehicleId($query->vehicleId)
            : $this->routes->findByVehicleAndDate($query->vehicleId, RouteDate::fromString($query->routeDate));

        return $route === null ? null : DriverRouteView::fromRoute($route);
    }
}
