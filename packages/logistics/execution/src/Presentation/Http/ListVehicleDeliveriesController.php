<?php

declare(strict_types=1);

namespace Logistics\Execution\Presentation\Http;

use App\Http\Support\ApiPagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Logistics\Execution\Application\Query\ListDeliveriesByVehicle\ListDeliveriesByVehicle;
use Logistics\Execution\Application\ReadModel\DeliveryView;
use Logistics\Execution\Application\ReadModel\VehicleDeliveriesPage;

/**
 * GET /app/vehicles/{vehicleId}/deliveries — hoja de ruta del chofer: las entregas asignadas a un vehículo
 * (puerta app), con referencia legible (pedido, cliente, dirección corta) y bultos (units_total,
 * units_loaded, unit_ids) para nombrar cada envío en la pantalla de carga.
 *
 * Paginación estándar OPT-IN (ApiPagination): con `page`/`per_page` o `cursor`, la página se recorta en
 * SQL y la meta trae los totales; sin parámetros, la colección completa con `meta.count` (histórico).
 */
final class ListVehicleDeliveriesController extends ExecutionController
{
    public function __invoke(Request $request, string $vehicleId): JsonResponse
    {
        $paged = ApiPagination::requested($request);

        /** @var VehicleDeliveriesPage $page */
        $page = $this->queryBus->ask(new ListDeliveriesByVehicle(
            $vehicleId,
            $paged ? ApiPagination::limit($request) : null,
            $paged ? ApiPagination::offset($request) : 0,
        ));

        $items = array_map(static fn (DeliveryView $view): array => $view->toArray(), $page->items);

        if ($paged) {
            return new JsonResponse(ApiPagination::envelope($items, $page->total, $request));
        }

        return new JsonResponse([
            'data' => $items,
            'meta' => ['count' => count($items)],
        ]);
    }
}
