<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Logistics\FleetOps\Application\Command\ActivatePlan\ActivatePlan;
use Logistics\FleetOps\Presentation\Http\ApiController;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Activa un plan de mantenimiento (comando ActivatePlan). El id llega por la ruta.
 */
final class ActivatePlanController extends ApiController
{
    public function __construct(
        private readonly CommandBus $commands,
    ) {}

    public function __invoke(string $planId): JsonResponse
    {
        return $this->guard(function () use ($planId): JsonResponse {
            $this->commands->dispatch(new ActivatePlan(planId: $planId));

            return $this->accepted(['plan_id' => $planId]);
        });
    }
}
