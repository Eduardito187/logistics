<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Presentation\Http\Controller;

use App\Http\Support\ApiPagination;
use Illuminate\Http\JsonResponse;
use Logistics\FleetOps\Application\Query\ListDepartures\ListDepartures;
use Logistics\FleetOps\Application\ReadModel\DepartureView;
use Logistics\FleetOps\Presentation\Http\ApiController;
use Logistics\FleetOps\Presentation\Http\Request\ListDeparturesRequest;
use Logistics\Shared\Application\Query\QueryBus;
use Logistics\Shared\Application\ReadModel\Page;

/**
 * Lista salidas (más recientes primero) con filtros opcionales por vehículo, estado y rango de fecha de
 * salida (ListDepartures).
 *
 * Paginación estándar opt-in (#20): con `page`/`per_page` o `cursor` recorta en SQL (LIMIT/OFFSET + COUNT
 * aparte) y devuelve el `meta` del proyecto; sin ellos, la colección completa (contrato histórico).
 */
final class ListDeparturesController extends ApiController
{
    public function __construct(
        private readonly QueryBus $queries,
    ) {}

    public function __invoke(ListDeparturesRequest $request): JsonResponse
    {
        return $this->guard(function () use ($request): JsonResponse {
            $paginate = ApiPagination::requested($request);

            $result = $this->queries->ask(new ListDepartures(
                vehicleId: $request->filled('vehicle_id') ? $request->string('vehicle_id')->toString() : null,
                status: $request->filled('status') ? $request->string('status')->toString() : null,
                from: $request->filled('from') ? $request->string('from')->toString() : null,
                to: $request->filled('to') ? $request->string('to')->toString() : null,
                limit: $paginate ? ApiPagination::limit($request) : null,
                offset: $paginate ? ApiPagination::offset($request) : null,
            ));

            if ($result instanceof Page) {
                /** @var Page<DepartureView> $result */
                $items = array_map(static fn (DepartureView $view): array => $view->toArray(), $result->items);

                return new JsonResponse(ApiPagination::envelope($items, $result->total, $request));
            }

            /** @var list<DepartureView> $result */
            return $this->data(
                array_map(static fn (DepartureView $view): array => $view->toArray(), $result),
            );
        });
    }
}
