<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Logistics\FleetOps\Application\Query\GetMaintenanceOrder\GetMaintenanceOrder;
use Logistics\FleetOps\Application\ReadModel\MaintenanceOrderView;
use Logistics\FleetOps\Presentation\Http\ApiController;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Devuelve una orden de mantenimiento por su id (consulta GetMaintenanceOrder). Responde 404 si no existe.
 */
final class GetMaintenanceOrderController extends ApiController
{
    public function __construct(
        private readonly QueryBus $queries,
    ) {}

    public function __invoke(string $orderId): JsonResponse
    {
        return $this->guard(function () use ($orderId): JsonResponse {
            /** @var MaintenanceOrderView|null $order */
            $order = $this->queries->ask(new GetMaintenanceOrder(orderId: $orderId));

            if ($order === null) {
                return $this->error('maintenance_order_not_found', 'No maintenance order found for the given id.', 404);
            }

            return $this->data($order->toArray());
        });
    }
}
