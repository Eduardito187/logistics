<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Logistics\Shared\Application\Query\QueryBus;
use Logistics\Telemetry\Application\Query\GetShipmentEta\GetShipmentEta;
use Logistics\Telemetry\Application\ReadModel\VehicleEtaView;
use Logistics\Telemetry\Presentation\Http\ApiController;

/**
 * ETA dinámica de un envío (su parada en la ruta del día del vehículo que lo lleva), recalculada desde la
 * última posición. Sin tráfico en vivo (`traffic_aware: false`). 404 si no tiene entrega asignada.
 */
final class GetShipmentEtaController extends ApiController
{
    public function __construct(
        private readonly QueryBus $queries,
    ) {}

    public function __invoke(string $shipmentId): JsonResponse
    {
        return $this->guard(function () use ($shipmentId): JsonResponse {
            /** @var VehicleEtaView|null $view */
            $view = $this->queries->ask(new GetShipmentEta($shipmentId));

            if ($view === null) {
                return $this->error('shipment_not_dispatched', 'The shipment has no delivery assigned to a vehicle.', 404);
            }

            return $this->data(['shipment_id' => $shipmentId] + $view->toArray());
        });
    }
}
