<?php

declare(strict_types=1);

namespace Logistics\Execution\Presentation\Http;

use Illuminate\Http\JsonResponse;
use Logistics\Execution\Application\Command\OpenNodeReception\OpenNodeReception;
use Logistics\Execution\Application\Query\ResolveNodeReceptionTarget\ResolveNodeReceptionTarget;
use Logistics\Execution\Application\ReadModel\NodeReceptionTarget;
use Logistics\Execution\Presentation\Http\Concerns\ScopesNodeReceptionToStore;
use Logistics\Execution\Presentation\Http\Request\OpenNodeReceptionRequest;

/**
 * POST /app/node-receptions — abre la recepción de un envío en un nodo destino (puerta app).
 *
 * Acepta la forma corta `{shipment_id, occurred_at}`: el servidor DERIVA el nodo destino (el del envío o, si
 * no está poblado, el del usuario), el nodo de origen (base del camión / CD que abastece al destino) y los
 * productos esperados (los del envío). La forma completa histórica sigue funcionando igual. Idempotente por
 * el par envío+destino: reabrir no duplica.
 *
 * Alcance por tienda: como el destino puede derivarse, el middleware `enforce.store.node` no aplica; el
 * controller exige que un usuario Tienda solo abra recepciones en SU nodo (403 RFC 7807 si no).
 */
final class OpenNodeReceptionController extends ExecutionController
{
    use ScopesNodeReceptionToStore;

    public function __invoke(OpenNodeReceptionRequest $request): JsonResponse
    {
        /** @var array<string, mixed> $data */
        $data = $request->validated();

        $storeNode = $this->storeScopeNode($request);
        if ($storeNode === '') {
            return $this->storeScopeForbidden($request);
        }

        $expected = isset($data['expected_unit_ids']) && is_array($data['expected_unit_ids'])
            ? array_values(array_map('strval', $data['expected_unit_ids']))
            : null;

        /** @var NodeReceptionTarget $target */
        $target = $this->queryBus->ask(new ResolveNodeReceptionTarget(
            shipmentId: (string) $data['shipment_id'],
            originNodeId: self::stringOrNull($data['origin_node_id'] ?? null),
            destinationNodeId: self::stringOrNull($data['destination_node_id'] ?? null),
            originNodeKind: self::stringOrNull($data['origin_node_kind'] ?? null),
            destinationNodeKind: self::stringOrNull($data['destination_node_kind'] ?? null),
            expectedUnitIds: $expected,
            fallbackDestinationNodeId: $storeNode ?? $this->userNodeId($request),
        ));

        if ($storeNode !== null && $target->destinationNodeId !== $storeNode) {
            return $this->storeScopeForbidden($request);
        }

        $this->commandBus->dispatch(new OpenNodeReception(
            shipmentId: $target->shipmentId,
            originNodeId: $target->originNodeId,
            destinationNodeId: $target->destinationNodeId,
            originNodeKind: $target->originNodeKind,
            destinationNodeKind: $target->destinationNodeKind,
            expectedUnitIds: $target->expectedUnitIds,
            occurredAt: (string) $data['occurred_at'],
        ));

        return $this->nodeReceptionResponse($target->shipmentId, $target->destinationNodeId, 201);
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
