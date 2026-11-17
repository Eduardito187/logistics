<?php

declare(strict_types=1);

namespace Logistics\Execution\Presentation\Http;

use Illuminate\Http\JsonResponse;
use Logistics\Execution\Application\Command\DeactivateFailureReason\DeactivateFailureReason;
use Logistics\Execution\Presentation\Http\Concerns\ResolvesFailureReasonView;

/**
 * DELETE /app/admin/failure-reasons/{code} — DESACTIVA (soft) un motivo del catálogo. Decisión del dueño:
 * nunca borrado físico. Responde 200 con el motivo ya inactivo.
 */
final class DeactivateFailureReasonController extends ExecutionController
{
    use ResolvesFailureReasonView;

    public function __invoke(string $code): JsonResponse
    {
        $this->commandBus->dispatch(new DeactivateFailureReason($code));

        return new JsonResponse(['data' => $this->failureReasonByCode($this->queryBus, $code)]);
    }
}
