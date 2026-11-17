<?php

declare(strict_types=1);

namespace Logistics\Execution\Presentation\Http;

use Illuminate\Http\JsonResponse;
use Logistics\Execution\Application\Query\ListActiveFailureReasons\ListActiveFailureReasons;
use Logistics\Execution\Application\ReadModel\FailureReasonView;

/**
 * GET /app/config/failure-reasons — catálogo ACTIVO de motivos de entrega fallida, ordenado, para que el
 * chofer elija al marcar una entrega como no realizada. Sin gating especial: cualquier usuario app
 * autenticado. La identidad de cada motivo en el contrato es su `code` (nunca el UUID interno).
 */
final class ListActiveFailureReasonsController extends ExecutionController
{
    public function __invoke(): JsonResponse
    {
        /** @var list<FailureReasonView> $views */
        $views = $this->queryBus->ask(new ListActiveFailureReasons());

        return new JsonResponse([
            'data' => array_map(static fn (FailureReasonView $view): array => $view->toArray(), $views),
        ]);
    }
}
