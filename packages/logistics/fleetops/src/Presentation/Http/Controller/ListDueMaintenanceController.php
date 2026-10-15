<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Logistics\FleetOps\Application\Query\ListDueMaintenance\ListDueMaintenance;
use Logistics\FleetOps\Application\ReadModel\DueMaintenanceView;
use Logistics\FleetOps\Presentation\Http\ApiController;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Lista los planes de mantenimiento preventivo por vencer o vencidos a hoy (consulta ListDueMaintenance).
 */
final class ListDueMaintenanceController extends ApiController
{
    public function __construct(
        private readonly QueryBus $queries,
    ) {}

    public function __invoke(): JsonResponse
    {
        return $this->guard(function (): JsonResponse {
            /** @var list<DueMaintenanceView> $due */
            $due = $this->queries->ask(new ListDueMaintenance);

            return $this->data(
                array_map(static fn (DueMaintenanceView $view): array => $view->toArray(), $due),
            );
        });
    }
}
