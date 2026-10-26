<?php

declare(strict_types=1);

namespace Logistics\Execution\Presentation\Http;

use Illuminate\Http\JsonResponse;
use Logistics\Execution\Application\Command\OpenNodeReception\OpenNodeReception;
use Logistics\Execution\Presentation\Http\Request\OpenNodeReceptionRequest;

/**
 * POST /app — abre la recepción de un envío en un nodo destino (puerta app).
 *
 * Anuncia qué productos se esperan y por qué corredor (tipo de nodo origen/destino). Idempotente por el par
 * envío+destino: reabrir no duplica.
 */
final class OpenNodeReceptionController extends ExecutionController
{
    public function __invoke(OpenNodeReceptionRequest $request): JsonResponse
    {
        /** @var array<string, mixed> $data */
        $data = $request->validated();

        /** @var list<string> $expectedUnitIds */
        $expectedUnitIds = array_values(array_map('strval', (array) $data['expected_unit_ids']));

        $this->commandBus->dispatch(new OpenNodeReception(
            shipmentId: (string) $data['shipment_id'],
            originNodeId: (string) $data['origin_node_id'],
            destinationNodeId: (string) $data['destination_node_id'],
            originNodeKind: (string) $data['origin_node_kind'],
            destinationNodeKind: (string) $data['destination_node_kind'],
            expectedUnitIds: $expectedUnitIds,
            occurredAt: (string) $data['occurred_at'],
        ));

        return $this->nodeReceptionResponse(
            (string) $data['shipment_id'],
            (string) $data['destination_node_id'],
            201,
        );
    }
}
