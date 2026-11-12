<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Presentation\Http\Controller;

use App\Http\Support\ApiPagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Logistics\FleetOps\Application\Query\ListMaintenanceOrders\ListMaintenanceOrders;
use Logistics\FleetOps\Application\ReadModel\MaintenanceOrderView;
use Logistics\FleetOps\Presentation\Http\ApiController;
use Logistics\Shared\Application\Query\QueryBus;
use Logistics\Shared\Application\ReadModel\Page;

/**
 * Lista órdenes de mantenimiento (agendadas más recientes primero) con filtros opcionales por vehículo
 * (?vehicle_id=), estado (?status=) y rango de fecha agendada (?from=&to=, ISO 8601).
 *
 * Paginación estándar opt-in (#20): con `page`/`per_page` o `cursor` recorta en SQL (LIMIT/OFFSET + COUNT
 * aparte) y devuelve el `meta` del proyecto; sin ellos, la colección completa (contrato histórico).
 */
final class ListMaintenanceOrdersController extends ApiController
{
    public function __construct(
        private readonly QueryBus $queries,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        // Validación fuera del guard: un 422 de validación no debe convertirse en 500.
        $validated = $request->validate([
            'vehicle_id' => ['sometimes', 'nullable', 'string', 'uuid'],
            'status' => ['sometimes', 'nullable', 'string', 'max:30'],
            'from' => ['sometimes', 'nullable', 'string', 'date'],
            'to' => ['sometimes', 'nullable', 'string', 'date'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:200'],
            'cursor' => ['sometimes', 'string', 'max:200'],
        ]);

        return $this->guard(function () use ($request, $validated): JsonResponse {
            $paginate = ApiPagination::requested($request);

            $result = $this->queries->ask(new ListMaintenanceOrders(
                vehicleId: self::filled($validated, 'vehicle_id'),
                status: self::filled($validated, 'status'),
                from: self::filled($validated, 'from'),
                to: self::filled($validated, 'to'),
                limit: $paginate ? ApiPagination::limit($request) : null,
                offset: $paginate ? ApiPagination::offset($request) : null,
            ));

            if ($result instanceof Page) {
                /** @var Page<MaintenanceOrderView> $result */
                $items = array_map(static fn (MaintenanceOrderView $view): array => $view->toArray(), $result->items);

                return new JsonResponse(ApiPagination::envelope($items, $result->total, $request));
            }

            /** @var list<MaintenanceOrderView> $result */
            return $this->data(
                array_map(static fn (MaintenanceOrderView $view): array => $view->toArray(), $result),
            );
        });
    }

    /**
     * Valor de texto no vacío del filtro, o null.
     *
     * @param array<string, mixed> $validated
     */
    private static function filled(array $validated, string $key): ?string
    {
        $value = $validated[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
