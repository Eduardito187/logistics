<?php

declare(strict_types=1);

namespace Logistics\Carriers\Presentation\Http\Controller;

use App\Http\Problem\ErrorCode;
use App\Http\Problem\ProblemDetails;
use App\Http\Support\ApiPagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Logistics\Carriers\Application\Query\ListCarrierAssignments\ListCarrierAssignments;
use Logistics\Carriers\Application\ReadModel\CarrierAssignmentView;
use Logistics\Carriers\Presentation\Http\Middleware\EnsureCarrierScope;
use Logistics\Shared\Application\Query\QueryBus;
use Logistics\Shared\Application\ReadModel\Page;
use Logistics\Shared\Domain\Exception\DomainException;

/**
 * `GET /app/carriers/me/assignments`: viajes asignados con su carga (envíos → productos con nombre + SKU)
 * para que el transportista sepa QUÉ recoger y valide sus escaneos contra la lista.
 *
 * Alcance: el transportista ve SOLO los de su transportista (el que {@see EnsureCarrierScope} deja en el
 * request; se ignora cualquier `?carrier_id=`). Oficina ve todos, con `?carrier_id=` opcional.
 * Filtro `?status=` (uno o varios separados por coma: scheduled,in_progress,closed,cancelled); por
 * defecto los vigentes (scheduled + in_progress). Paginación OBLIGATORIA (cursor o página, per_page 50 por
 * defecto, máx. 200) con recorte en SQL, mismo envelope `data` + `meta` que el resto de la API.
 */
final class ListCarrierAssignmentsController
{
    public function __construct(
        private readonly QueryBus $queries,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $carrierId = $request->query('carrier_id');
        $carrierId = \is_string($carrierId) && $carrierId !== '' ? $carrierId : null;

        $scoped = $request->attributes->get(EnsureCarrierScope::ATTRIBUTE);
        if (\is_string($scoped) && $scoped !== '') {
            $carrierId = $scoped;
        }

        $rawStatus = $request->query('status');
        $statuses = \is_string($rawStatus) && trim($rawStatus) !== ''
            ? array_values(array_filter(array_map('trim', explode(',', $rawStatus)), static fn (string $s): bool => $s !== ''))
            : [];

        try {
            /** @var Page<CarrierAssignmentView> $page */
            $page = $this->queries->ask(new ListCarrierAssignments(
                carrierId: $carrierId,
                statuses: $statuses,
                limit: ApiPagination::limit($request),
                offset: ApiPagination::offset($request),
            ));
        } catch (DomainException $e) {
            return ProblemDetails::fromCode(ErrorCode::ValidationFailed, $e->getMessage())->toResponse($request);
        }

        $items = array_map(static fn (CarrierAssignmentView $view): array => $view->toArray(), $page->items);

        return new JsonResponse(ApiPagination::envelope($items, $page->total, $request));
    }
}
