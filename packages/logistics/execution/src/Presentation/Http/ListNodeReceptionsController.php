<?php

declare(strict_types=1);

namespace Logistics\Execution\Presentation\Http;

use App\Http\Support\ApiPagination;
use Illuminate\Http\JsonResponse;
use Logistics\Execution\Application\Query\ListNodeReceptions\ListNodeReceptions;
use Logistics\Execution\Application\ReadModel\NodeReceptionSummaryView;
use Logistics\Execution\Presentation\Http\Concerns\ScopesNodeReceptionToStore;
use Logistics\Execution\Presentation\Http\Request\ListNodeReceptionsRequest;

/**
 * GET /app/node-receptions?node_id=&status=open — lista paginada de recepciones en nodo (puerta app).
 *
 * Cada ítem trae el envío (`order_reference`, `customer_name`) y `reception: {status, expected_count,
 * received_count, missing_count, surplus_count, opened_at, reconciled_at}`, resuelto en lote (sin N+1).
 * Siempre paginado (`data` + `meta` estándar de ApiPagination; por defecto página 1 de 50).
 *
 * Alcance por tienda: un usuario Tienda solo ve las recepciones de SU nodo (sin `node_id` se usa el suyo;
 * otro nodo → 403 RFC 7807).
 */
final class ListNodeReceptionsController extends ExecutionController
{
    use ScopesNodeReceptionToStore;

    public function __invoke(ListNodeReceptionsRequest $request): JsonResponse
    {
        /** @var array<string, mixed> $data */
        $data = $request->validated();

        $nodeId = isset($data['node_id']) && is_string($data['node_id']) && $data['node_id'] !== '' ? $data['node_id'] : null;
        $status = isset($data['status']) && is_string($data['status']) && $data['status'] !== '' ? $data['status'] : null;

        $storeNode = $this->storeScopeNode($request);
        if ($storeNode !== null) {
            if ($storeNode === '' || ($nodeId !== null && $nodeId !== $storeNode)) {
                return $this->storeScopeForbidden($request);
            }
            $nodeId = $storeNode;
        }

        /** @var array{items: list<NodeReceptionSummaryView>, total: int} $page */
        $page = $this->queryBus->ask(new ListNodeReceptions(
            nodeId: $nodeId,
            status: $status,
            limit: ApiPagination::limit($request),
            offset: ApiPagination::offset($request),
        ));

        $items = array_map(static fn (NodeReceptionSummaryView $view): array => $view->toArray(), $page['items']);

        return new JsonResponse(ApiPagination::envelope($items, $page['total'], $request));
    }
}
