<?php

declare(strict_types=1);

namespace Logistics\Execution\Presentation\Http;

use Illuminate\Http\JsonResponse;
use Logistics\Execution\Application\Command\AssignShelfLocation\AssignShelfLocation;
use Logistics\Execution\Presentation\Http\Request\AssignShelfLocationRequest;

/**
 * POST /app/node-receptions/{shipmentId}/units/{unitId}/location — ubica un producto en estante.
 * POST /app/node-receptions/{shipmentId}/units/location — ubica un lote (`unit_ids`) en el mismo estante.
 *
 * Idempotente (misma ubicación = sin cambios; una hora anterior a la vigente se ignora). Devuelve el estado
 * de la recepción con `location`/`located_at` en cada producto. 422 si un producto no es de la recepción.
 */
final class AssignShelfLocationController extends ExecutionController
{
    public function __invoke(AssignShelfLocationRequest $request, string $shipmentId, ?string $unitId = null): JsonResponse
    {
        /** @var array<string, mixed> $data */
        $data = $request->validated();

        $unitIds = $unitId !== null
            ? [$unitId]
            : array_values(array_map('strval', (array) ($data['unit_ids'] ?? [])));

        $nodeId = (string) $data['node_id'];

        $this->commandBus->dispatch(new AssignShelfLocation(
            shipmentId: $shipmentId,
            nodeId: $nodeId,
            unitIds: $unitIds,
            location: (string) $data['location'],
            deviceId: $this->deviceId($request),
            userId: $this->userId($request),
            occurredAt: (string) $data['occurred_at'],
        ));

        return $this->nodeReceptionResponse($shipmentId, $nodeId);
    }
}
