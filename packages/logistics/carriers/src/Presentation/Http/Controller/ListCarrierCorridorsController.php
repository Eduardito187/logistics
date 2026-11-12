<?php

declare(strict_types=1);

namespace Logistics\Carriers\Presentation\Http\Controller;

use App\Http\Support\ApiPagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Logistics\Carriers\Application\Query\ListCarrierCorridors\ListCarrierCorridors;
use Logistics\Carriers\Application\ReadModel\CarrierCorridorView;
use Logistics\Carriers\Presentation\Http\ApiController;
use Logistics\Carriers\Presentation\Http\Middleware\EnsureCarrierScope;
use Logistics\Shared\Application\Query\QueryBus;
use Logistics\Shared\Application\ReadModel\Page;

/**
 * `GET /app/carriers/corridors`: corredores tarifados (distintos) con sus clases de vehículo y etiquetas
 * legibles, armados desde las tarifas (consulta ListCarrierCorridors).
 *
 * `from` / `to` son el slug EXACTO que acepta `GET {carrierId}/tariff` (la app los reenvía tal cual);
 * `from_label` / `to_label` son solo para mostrar.
 *
 * Alcance: el transportista ve SOLO los corredores de su transportista (el que EnsureCarrierScope deja
 * en el request; se ignora cualquier `?carrier_id=`). Oficina ve todos, con `?carrier_id=` opcional.
 *
 * Paginación opt-in (catálogo acotado): sin `page`/`cursor`/`per_page` devuelve la lista completa en
 * `data`; con ellos, recorte en SQL y envelope `data` + `meta` como el resto de la API.
 */
final class ListCarrierCorridorsController extends ApiController
{
    public function __construct(
        private readonly QueryBus $queries,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        return $this->guard(function () use ($request): JsonResponse {
            $carrierId = $request->query('carrier_id');
            $carrierId = is_string($carrierId) && $carrierId !== '' ? $carrierId : null;

            $scopedCarrierId = $request->attributes->get(EnsureCarrierScope::ATTRIBUTE);
            if (is_string($scopedCarrierId) && $scopedCarrierId !== '') {
                $carrierId = $scopedCarrierId;
            }

            if (!ApiPagination::requested($request)) {
                /** @var list<CarrierCorridorView> $corridors */
                $corridors = $this->queries->ask(new ListCarrierCorridors(carrierId: $carrierId));

                return $this->data(array_map(static fn (CarrierCorridorView $view): array => $view->toArray(), $corridors));
            }

            /** @var Page<CarrierCorridorView> $page */
            $page = $this->queries->ask(new ListCarrierCorridors(
                carrierId: $carrierId,
                limit: ApiPagination::limit($request),
                offset: ApiPagination::offset($request),
            ));

            $items = array_map(static fn (CarrierCorridorView $view): array => $view->toArray(), $page->items);

            return new JsonResponse(ApiPagination::envelope($items, $page->total, $request));
        });
    }
}
