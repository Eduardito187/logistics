<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Presentation\Http\Controller;

use App\Http\Support\ApiPagination;
use Illuminate\Http\JsonResponse;
use Logistics\FleetOps\Application\Query\ListRefuels\ListRefuels;
use Logistics\FleetOps\Application\ReadModel\RefuelView;
use Logistics\FleetOps\Presentation\Http\ApiController;
use Logistics\FleetOps\Presentation\Http\Request\ListRefuelsRequest;
use Logistics\Shared\Application\Query\QueryBus;
use Logistics\Shared\Application\ReadModel\Page;

/**
 * Lista cargas de combustible con filtros opcionales por vehículo y rango de fechas (consulta ListRefuels).
 *
 * Tabla append-only de crecimiento ilimitado: paginación OBLIGATORIA (per_page por defecto 50), con
 * recorte en SQL (LIMIT/OFFSET + COUNT(*) aparte).
 */
final class ListRefuelsController extends ApiController
{
    public function __construct(
        private readonly QueryBus $queries,
    ) {}

    public function __invoke(ListRefuelsRequest $request): JsonResponse
    {
        return $this->guard(function () use ($request): JsonResponse {
            /** @var Page<RefuelView> $page */
            $page = $this->queries->ask(new ListRefuels(
                vehicleId: $request->filled('vehicle_id') ? $request->string('vehicle_id')->toString() : null,
                from: $request->filled('from') ? $request->string('from')->toString() : null,
                to: $request->filled('to') ? $request->string('to')->toString() : null,
                limit: ApiPagination::limit($request),
                offset: ApiPagination::offset($request),
            ));

            $items = array_map(static fn (RefuelView $view): array => $view->toArray(), $page->items);

            return new JsonResponse(ApiPagination::envelope($items, $page->total, $request));
        });
    }
}
