<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Logistics\FleetOps\Application\Command\CompleteMaintenance\CompleteMaintenance;
use Logistics\FleetOps\Presentation\Http\ApiController;
use Logistics\FleetOps\Presentation\Http\Request\CompleteMaintenanceRequest;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Completa una orden de mantenimiento con sus ítems (comando CompleteMaintenance).
 *
 * El id llega por la ruta; los ítems (repuestos + mano de obra) y la moneda por el cuerpo. El agregado
 * calcula el costo total sumando cada ítem.
 */
final class CompleteMaintenanceController extends ApiController
{
    public function __construct(
        private readonly CommandBus $commands,
    ) {}

    public function __invoke(CompleteMaintenanceRequest $request, string $orderId): JsonResponse
    {
        return $this->guard(function () use ($request, $orderId): JsonResponse {
            /** @var list<array{description: string, qty: int, part_cost_cents: int, labor_cost_cents: int}> $items */
            $items = array_values($request->array('items'));

            $this->commands->dispatch(new CompleteMaintenance(
                orderId: $orderId,
                items: $items,
                currency: $request->string('currency')->toString(),
            ));

            return $this->accepted(['order_id' => $orderId]);
        });
    }
}
