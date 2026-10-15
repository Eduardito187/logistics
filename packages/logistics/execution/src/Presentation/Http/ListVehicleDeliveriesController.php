<?php

declare(strict_types=1);

namespace Logistics\Execution\Presentation\Http;

use Illuminate\Http\JsonResponse;
use Logistics\Execution\Application\Query\ListDeliveriesByVehicle\ListDeliveriesByVehicle;
use Logistics\Execution\Application\ReadModel\DeliveryView;

/**
 * GET /app — hoja de ruta del chofer: las entregas asignadas a un vehículo (puerta app).
 */
final class ListVehicleDeliveriesController extends ExecutionController
{
    public function __invoke(string $vehicleId): JsonResponse
    {
        /** @var list<DeliveryView> $views */
        $views = $this->queryBus->ask(new ListDeliveriesByVehicle($vehicleId));

        return new JsonResponse([
            'data' => array_map(static fn (DeliveryView $view): array => $view->toArray(), $views),
            'meta' => ['count' => count($views)],
        ]);
    }
}
