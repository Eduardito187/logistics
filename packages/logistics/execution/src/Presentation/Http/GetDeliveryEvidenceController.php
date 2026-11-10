<?php

declare(strict_types=1);

namespace Logistics\Execution\Presentation\Http;

use Illuminate\Http\JsonResponse;
use Logistics\Execution\Application\Query\GetDeliveryEvidence\GetDeliveryEvidence;
use Logistics\Execution\Application\ReadModel\DeliveryEvidenceView;

/**
 * GET /app/deliveries/{shipmentId}/evidence — lista las evidencias del envío con su ESTADO DE VALIDACIÓN
 * anti-fraude (pending|ok|review|mismatch), más recientes primero. La app muestra el estado por evidencia.
 */
final class GetDeliveryEvidenceController extends ExecutionController
{
    public function __invoke(string $shipmentId): JsonResponse
    {
        /** @var list<DeliveryEvidenceView> $views */
        $views = $this->queryBus->ask(new GetDeliveryEvidence($shipmentId));

        return new JsonResponse([
            'data' => array_map(static fn (DeliveryEvidenceView $v): array => $v->toArray(), $views),
        ]);
    }
}
