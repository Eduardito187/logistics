<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Logistics\FleetOps\Application\Command\DeactivatePlan\DeactivatePlan;
use Logistics\FleetOps\Presentation\Http\ApiController;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Desactiva un plan de mantenimiento (comando DeactivatePlan). El id llega por la ruta.
 */
final class DeactivatePlanController extends ApiController
{
    public function __construct(
        private readonly CommandBus $commands,
    ) {}

    public function __invoke(string $planId): JsonResponse
    {
        return $this->guard(function () use ($planId): JsonResponse {
            $this->commands->dispatch(new DeactivatePlan(planId: $planId));

            return $this->accepted(['plan_id' => $planId]);
        });
    }
}
