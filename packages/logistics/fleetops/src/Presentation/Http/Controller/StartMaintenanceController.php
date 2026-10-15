<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Logistics\FleetOps\Application\Command\StartMaintenance\StartMaintenance;
use Logistics\FleetOps\Presentation\Http\ApiController;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Inicia una orden de mantenimiento agendada (comando StartMaintenance). El id llega por la ruta.
 */
final class StartMaintenanceController extends ApiController
{
    public function __construct(
        private readonly CommandBus $commands,
    ) {}

    public function __invoke(string $orderId): JsonResponse
    {
        return $this->guard(function () use ($orderId): JsonResponse {
            $this->commands->dispatch(new StartMaintenance(orderId: $orderId));

            return $this->accepted(['order_id' => $orderId]);
        });
    }
}
