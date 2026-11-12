<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Logistics\Shared\Application\Query\QueryBus;
use Logistics\Telemetry\Application\Query\GetShipmentBreadcrumbs\GetShipmentBreadcrumbs;
use Logistics\Telemetry\Application\ReadModel\ShipmentBreadcrumbsView;
use Logistics\Telemetry\Presentation\Http\ApiController;

/**
 * Migas de pan de un envío: la traza reducida del vehículo mientras el envío estuvo a bordo (carga →
 * entrega/fallo, o hasta ahora). 404 si el envío no tiene entrega asignada (consulta GetShipmentBreadcrumbs).
 */
final class GetShipmentBreadcrumbsController extends ApiController
{
    public function __construct(
        private readonly QueryBus $queries,
    ) {}

    public function __invoke(string $shipmentId): JsonResponse
    {
        return $this->guard(function () use ($shipmentId): JsonResponse {
            /** @var ShipmentBreadcrumbsView|null $view */
            $view = $this->queries->ask(new GetShipmentBreadcrumbs($shipmentId));

            if ($view === null) {
                return $this->error('shipment_not_dispatched', 'The shipment has no delivery assigned to a vehicle.', 404);
            }

            return $this->data($view->toArray());
        });
    }
}
