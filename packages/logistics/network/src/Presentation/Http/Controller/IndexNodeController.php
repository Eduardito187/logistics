<?php

declare(strict_types=1);

namespace Logistics\Network\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Logistics\Network\Application\Query\ListNodes\ListNodesQuery;
use Logistics\Network\Application\ReadModel\NodeView;
use Logistics\Network\Presentation\Http\Request\IndexNodeRequest;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Lista los nodos de la red para la app interna (puerta app, solo lectura).
 *
 * Llama al QueryBus (nunca a Infrastructure ni al dominio). La consulta devuelve todos los nodos
 * y el filtrado por tipo/estado se aplica acá, sobre los modelos de lectura, porque el volumen de
 * nodos es chico (ver ListNodesQuery).
 */
final class IndexNodeController
{
    public function __invoke(IndexNodeRequest $request, QueryBus $bus): JsonResponse
    {
        /** @var list<NodeView> $nodes */
        $nodes = $bus->ask(new ListNodesQuery);

        $type = $request->typeFilter();
        $status = $request->statusFilter();

        $filtered = array_values(array_filter(
            $nodes,
            static fn (NodeView $node): bool => ($type === null || $node->type === $type)
                && ($status === null || $node->status === $status),
        ));

        $data = array_map(static fn (NodeView $node): array => $node->toArray(), $filtered);

        return new JsonResponse([
            'data' => $data,
            'meta' => ['count' => count($data)],
        ]);
    }
}
