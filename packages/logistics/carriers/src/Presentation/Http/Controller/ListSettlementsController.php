<?php

declare(strict_types=1);

namespace Logistics\Carriers\Presentation\Http\Controller;

use App\Http\Support\ApiPagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Logistics\Carriers\Application\Query\ListSettlements\ListSettlements;
use Logistics\Carriers\Application\ReadModel\SettlementView;
use Logistics\Carriers\Presentation\Http\ApiController;
use Logistics\Carriers\Presentation\Http\Middleware\EnsureCarrierScope;
use Logistics\Shared\Application\Query\QueryBus;
use Logistics\Shared\Application\ReadModel\Page;

/**
 * Lista las liquidaciones como proyecciones de lectura, con filtros opcionales por transportista
 * (?carrier_id=), por estado (?status=) y por rango de período (?period_from= / ?period_to=, fechas
 * `Y-m-d` inclusivas; entra toda liquidación cuyo mes de facturación se solapa con el rango) (consulta
 * ListSettlements).
 *
 * Tabla de crecimiento ilimitado: paginación OBLIGATORIA (per_page por defecto 50), con recorte en SQL
 * (LIMIT/OFFSET + COUNT(*) aparte).
 *
 * Rol transportista: el `?carrier_id=` del cliente se IGNORA; el filtro es siempre el transportista del
 * usuario que {@see EnsureCarrierScope} deja en el request (defensa en profundidad además del middleware).
 */
final class ListSettlementsController extends ApiController
{
    public function __construct(
        private readonly QueryBus $queries,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        // Validación FUERA de guard: la ValidationException la traduce el renderer global a 422 RFC 7807
        // (guard la convertiría en 500).
        $request->validate([
            'period_from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'period_to' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        ]);

        $periodFrom = $this->dateParam($request, 'period_from');
        $periodTo = $this->dateParam($request, 'period_to');

        // Rango invertido: `Y-m-d` se ordena lexicográficamente igual que cronológicamente.
        if ($periodFrom !== null && $periodTo !== null && $periodTo < $periodFrom) {
            throw ValidationException::withMessages([
                'period_to' => 'The period_to must be a date after or equal to period_from.',
            ]);
        }

        return $this->guard(function () use ($request, $periodFrom, $periodTo): JsonResponse {
            $carrierId = $request->query('carrier_id');
            $carrierId = is_string($carrierId) && $carrierId !== '' ? $carrierId : null;

            $scopedCarrierId = $request->attributes->get(EnsureCarrierScope::ATTRIBUTE);
            if (is_string($scopedCarrierId) && $scopedCarrierId !== '') {
                $carrierId = $scopedCarrierId;
            }

            $status = $request->query('status');
            $status = is_string($status) && $status !== '' ? $status : null;

            /** @var Page<SettlementView> $page */
            $page = $this->queries->ask(new ListSettlements(
                carrierId: $carrierId,
                status: $status,
                limit: ApiPagination::limit($request),
                offset: ApiPagination::offset($request),
                periodFrom: $periodFrom,
                periodTo: $periodTo,
            ));

            $items = array_map(static fn (SettlementView $view): array => $view->toArray(), $page->items);

            return new JsonResponse(ApiPagination::envelope($items, $page->total, $request));
        });
    }

    /**
     * Fecha `Y-m-d` ya validada del query string; null si no vino o vino vacía.
     */
    private function dateParam(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
