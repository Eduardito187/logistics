<?php

declare(strict_types=1);

namespace Logistics\Execution\Presentation\Http;

use Illuminate\Http\JsonResponse;
use Logistics\Execution\Application\Query\ListFailureReasons\ListFailureReasons;
use Logistics\Execution\Application\ReadModel\FailureReasonView;

/**
 * GET /app/admin/failure-reasons — catálogo COMPLETO (activos e inactivos) para administrarlo. Gateado a
 * rol admin/planificador por el middleware de la ruta.
 */
final class ListFailureReasonsController extends ExecutionController
{
    public function __invoke(): JsonResponse
    {
        /** @var list<FailureReasonView> $views */
        $views = $this->queryBus->ask(new ListFailureReasons);

        return new JsonResponse([
            'data' => array_map(static fn (FailureReasonView $view): array => $view->toArray(), $views),
        ]);
    }
}
