<?php

declare(strict_types=1);

namespace Logistics\Execution\Application\Query\GetRouteStop;

use Logistics\Execution\Application\Contract\StopEnrichmentReader;
use Logistics\Execution\Application\ReadModel\DriverRouteView;
use Logistics\Execution\Application\ReadModel\RouteStopView;
use Logistics\Execution\Domain\Contract\DeliveryRepository;
use Logistics\Execution\Domain\Contract\VehicleRouteRepository;
use Logistics\Execution\Domain\Exception\ExecutionException;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;

/**
 * @implements QueryHandler<GetRouteStop, ?RouteStopView>
 */
final readonly class GetRouteStopHandler implements QueryHandler
{
    public function __construct(
        private VehicleRouteRepository $routes,
        private StopEnrichmentReader $stopEnrichment,
        private DeliveryRepository $deliveries,
    ) {}

    public function handle(Query $query): ?RouteStopView
    {
        if (!$query instanceof GetRouteStop) {
            throw ExecutionException::because('GetRouteStopHandler solo maneja GetRouteStop');
        }

        $route = $this->routes->findByShipmentId($query->shipmentId);
        if ($route === null) {
            return null;
        }

        foreach ($route->stops() as $stop) {
            if ($stop->shipmentId() === $query->shipmentId) {
                // POD ya capturado del envío (confirmación + foto), de la misma fuente que GET
                // /app/deliveries/{id}: así la app ve la prueba en la parada sin una llamada extra.
                $proof = $this->deliveries->findByShipmentId($query->shipmentId)?->proofOfDelivery();

                return DriverRouteView::enrichStop(
                    $stop,
                    $this->stopEnrichment->forShipmentIds([$query->shipmentId]),
                    $proof?->confirmation,
                    $proof?->photoUrl,
                );
            }
        }

        return null;
    }
}
