<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Logistics\FleetOps\Application\Command\CancelMaintenance\CancelMaintenance;
use Logistics\FleetOps\Presentation\Http\ApiController;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Cancela una orden de mantenimiento (comando CancelMaintenance). El id llega por la ruta.
 */
final class CancelMaintenanceController extends ApiController
{
    public function __construct(
        private readonly CommandBus $commands,
    ) {}

    public function __invoke(string $orderId): JsonResponse
    {
        return $this->guard(function () use ($orderId): JsonResponse {
            $this->commands->dispatch(new CancelMaintenance(orderId: $orderId));

            return $this->accepted(['order_id' => $orderId]);
        });
    }
}
