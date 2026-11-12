<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Presentation\Http\Controller;

use App\Http\Support\ApiPagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Logistics\FleetOps\Application\Query\ListDueMaintenance\ListDueMaintenance;
use Logistics\FleetOps\Application\ReadModel\DueMaintenanceView;
use Logistics\FleetOps\Presentation\Http\ApiController;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Lista los planes de mantenimiento preventivo por vencer o vencidos a hoy (consulta ListDueMaintenance),
 * más urgentes primero. Cada ítem tiene la forma estable `DueMaintenanceItem` (#24).
 *
 * Filtros opcionales: `vehicle_id` (el chofer ve solo su vehículo) y `urgency` (overdue | due_soon).
 * Paginación estándar opt-in (#20) con `page`/`per_page` o `cursor`; sin ellos, la colección completa.
 */
final class ListDueMaintenanceController extends ApiController
{
    public function __construct(
        private readonly QueryBus $queries,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        // Validación fuera del guard: un 422 de validación no debe convertirse en 500.
        $validated = $request->validate([
            'vehicle_id' => ['sometimes', 'nullable', 'string', 'uuid'],
            'urgency' => ['sometimes', 'nullable', 'string', 'in:'.DueMaintenanceView::URGENCY_OVERDUE.','.DueMaintenanceView::URGENCY_DUE_SOON],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:200'],
            'cursor' => ['sometimes', 'string', 'max:200'],
        ]);

        return $this->guard(function () use ($request, $validated): JsonResponse {
            $vehicleId = $validated['vehicle_id'] ?? null;
            $vehicleId = is_string($vehicleId) && $vehicleId !== '' ? $vehicleId : null;
            $urgency = $validated['urgency'] ?? null;
            $urgency = is_string($urgency) && $urgency !== '' ? $urgency : null;

            /** @var list<DueMaintenanceView> $due */
            $due = $this->queries->ask(new ListDueMaintenance(vehicleId: $vehicleId, urgency: $urgency));

            $items = array_map(static fn (DueMaintenanceView $view): array => $view->toArray(), $due);

            if (ApiPagination::requested($request)) {
                return new JsonResponse(ApiPagination::paginate($items, $request));
            }

            return $this->data($items);
        });
    }
}
