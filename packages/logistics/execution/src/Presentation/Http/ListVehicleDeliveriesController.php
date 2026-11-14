<?php

declare(strict_types=1);

namespace Logistics\Execution\Presentation\Http;

use App\Http\Support\ApiPagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Logistics\Execution\Application\Query\ListDeliveriesByVehicle\ListDeliveriesByVehicle;
use Logistics\Execution\Application\ReadModel\DeliveryView;
use Logistics\Execution\Application\ReadModel\VehicleDeliveriesPage;
use Logistics\Execution\Domain\ValueObject\DeliveryStatus;

/**
 * GET /app/vehicles/{vehicleId}/deliveries — hoja de ruta del chofer: las entregas asignadas a un vehículo
 * (puerta app), con referencia legible (pedido, cliente, dirección corta) y bultos (units_total,
 * units_loaded, unit_ids) para nombrar cada envío en la pantalla de carga.
 *
 * Filtro por estado (aditivo): `?status=pending,prepared,loaded` trae solo esos estados (sin distinguir
 * mayúsculas) y `?open=true` es el atajo de la pantalla de carga: excluye las entregadas y las fallidas.
 * Sin ninguno de los dos, la lista completa (histórico). Un estado desconocido se ignora.
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
            $this->statuses($request),
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

    /**
     * Estados pedidos por `status` (lista separada por comas) o por el atajo `open`. Vacío = sin filtro.
     *
     * @return list<string>
     */
    private function statuses(Request $request): array
    {
        $raw = $request->query('status');
        $statuses = [];
        foreach (explode(',', is_string($raw) ? $raw : '') as $candidate) {
            $status = DeliveryStatus::tryFromLoose(trim($candidate));
            if ($status !== null) {
                $statuses[$status->value] = $status->value;
            }
        }

        if ($statuses === [] && $request->boolean('open')) {
            foreach (DeliveryStatus::cases() as $status) {
                if ($status !== DeliveryStatus::Delivered && $status !== DeliveryStatus::Failed) {
                    $statuses[$status->value] = $status->value;
                }
            }
        }

        return array_values($statuses);
    }
}
