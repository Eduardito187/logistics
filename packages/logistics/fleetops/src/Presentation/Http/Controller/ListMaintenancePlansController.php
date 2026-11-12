<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Presentation\Http\Controller;

use App\Http\Support\ApiPagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Logistics\FleetOps\Application\Query\ListMaintenancePlans\ListMaintenancePlans;
use Logistics\FleetOps\Application\ReadModel\MaintenancePlanView;
use Logistics\FleetOps\Presentation\Http\ApiController;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Lista planes de mantenimiento preventivo con filtro opcional por vehículo (?vehicle_id=).
 *
 * Paginación estándar opt-in (#20): con `page`/`per_page` o `cursor` devuelve el `meta` del proyecto; sin
 * ellos, la colección completa (contrato histórico). Los planes son pocos por vehículo (uno por tipo de
 * servicio), así que la página se recorta sobre la colección ya filtrada.
 */
final class ListMaintenancePlansController extends ApiController
{
    public function __construct(
        private readonly QueryBus $queries,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        // Validación fuera del guard: un 422 de validación no debe convertirse en 500.
        $request->validate([
            'vehicle_id' => ['sometimes', 'nullable', 'string', 'uuid'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:200'],
            'cursor' => ['sometimes', 'string', 'max:200'],
        ]);

        return $this->guard(function () use ($request): JsonResponse {
            $vehicleId = $request->query('vehicle_id');
            $vehicleId = is_string($vehicleId) && $vehicleId !== '' ? $vehicleId : null;

            /** @var list<MaintenancePlanView> $plans */
            $plans = $this->queries->ask(new ListMaintenancePlans(vehicleId: $vehicleId));

            $items = array_map(static fn (MaintenancePlanView $view): array => $view->toArray(), $plans);

            if (ApiPagination::requested($request)) {
                return new JsonResponse(ApiPagination::paginate($items, $request));
            }

            return $this->data($items);
        });
    }
}
