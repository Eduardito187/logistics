<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Logistics\FleetOps\Application\Query\ListMaintenanceOrders\ListMaintenanceOrders;
use Logistics\FleetOps\Application\ReadModel\MaintenanceOrderView;
use Logistics\FleetOps\Presentation\Http\ApiController;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Lista órdenes de mantenimiento con filtros opcionales por vehículo (?vehicle_id=) y estado (?status=).
 */
final class ListMaintenanceOrdersController extends ApiController
{
    public function __construct(
        private readonly QueryBus $queries,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        return $this->guard(function () use ($request): JsonResponse {
            $vehicleId = $request->query('vehicle_id');
            $vehicleId = is_string($vehicleId) && $vehicleId !== '' ? $vehicleId : null;

            $status = $request->query('status');
            $status = is_string($status) && $status !== '' ? $status : null;

            /** @var list<MaintenanceOrderView> $orders */
            $orders = $this->queries->ask(new ListMaintenanceOrders(vehicleId: $vehicleId, status: $status));

            return $this->data(
                array_map(static fn (MaintenanceOrderView $view): array => $view->toArray(), $orders),
            );
        });
    }
}
