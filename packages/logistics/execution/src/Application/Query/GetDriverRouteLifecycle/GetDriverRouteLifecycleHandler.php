<?php

declare(strict_types=1);

namespace Logistics\Execution\Application\Query\GetDriverRouteLifecycle;

use Logistics\Execution\Application\ReadModel\DriverRouteView;
use Logistics\Execution\Application\Service\DriverRouteLocator;
use Logistics\Execution\Application\Service\StaleRouteStops;
use Logistics\Execution\Domain\Exception\ExecutionException;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;

/**
 * @implements QueryHandler<GetDriverRouteLifecycle, ?DriverRouteView>
 */
final readonly class GetDriverRouteLifecycleHandler implements QueryHandler
{
    public function __construct(
        private DriverRouteLocator $locator,
        private StaleRouteStops $staleStops,
    ) {}

    public function handle(Query $query): ?DriverRouteView
    {
        if (!$query instanceof GetDriverRouteLifecycle) {
            throw ExecutionException::because('GetDriverRouteLifecycleHandler solo maneja GetDriverRouteLifecycle');
        }

        $route = $this->locator->locate($query->vehicleId, $query->routeDate, $query->occurredAt);

        // Mismos contadores que GET /app/vehicles/{id}/route: sin las paradas de envíos ya cerrados antes del
        // día de la ruta (lectura defensiva, no persiste).
        return $route === null ? null : DriverRouteView::fromRoute($this->staleStops->hideFrom($route));
    }
}
