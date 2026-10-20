<?php

declare(strict_types=1);

namespace Logistics\Inbound\Presentation\Http\Controller;

use App\Http\Support\ApiPagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Logistics\Inbound\Application\Query\ListAsns\ListAsns;
use Logistics\Inbound\Application\ReadModel\AsnView;
use Logistics\Inbound\Presentation\Http\ApiController;
use Logistics\Shared\Application\Query\QueryBus;
use Logistics\Shared\Application\ReadModel\Page;

/**
 * Lista ASNs (GET /v1/inbound/asn) filtrando por seller, almacén, estado y/o fecha de cita.
 *
 * Tabla de crecimiento ilimitado: paginación OBLIGATORIA (per_page por defecto 50), con recorte en SQL
 * (LIMIT/OFFSET + COUNT(*) aparte).
 */
final class ListAsnsController extends ApiController
{
    public function __construct(
        private readonly QueryBus $queries,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        return $this->guard(function () use ($request): JsonResponse {
            /** @var Page<AsnView> $page */
            $page = $this->queries->ask(new ListAsns(
                sellerId: $this->filter($request, 'seller_id'),
                warehouseId: $this->filter($request, 'warehouse_id'),
                status: $this->filter($request, 'status'),
                appointmentDate: $this->filter($request, 'appointment_date'),
                limit: ApiPagination::limit($request),
                offset: ApiPagination::offset($request),
            ));

            $items = array_map(static fn (AsnView $asn): array => $asn->toArray(), $page->items);

            return new JsonResponse(ApiPagination::envelope($items, $page->total, $request));
        });
    }

    private function filter(Request $request, string $key): ?string
    {
        $value = $request->string($key)->toString();

        return $value !== '' ? $value : null;
    }
}
