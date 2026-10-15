<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Logistics\FleetOps\Application\Command\RecordServiceDone\RecordServiceDone;
use Logistics\FleetOps\Presentation\Http\ApiController;
use Logistics\FleetOps\Presentation\Http\Request\RecordServiceDoneRequest;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Registra que el servicio de un plan se ejecutó (comando RecordServiceDone). Recalcula el próximo
 * vencimiento. El id del plan llega por la ruta; el odómetro (km) es opcional.
 */
final class RecordServiceDoneController extends ApiController
{
    public function __construct(
        private readonly CommandBus $commands,
    ) {}

    public function __invoke(RecordServiceDoneRequest $request, string $planId): JsonResponse
    {
        return $this->guard(function () use ($request, $planId): JsonResponse {
            $this->commands->dispatch(new RecordServiceDone(
                planId: $planId,
                odometerKm: $request->filled('odometer_km') ? $request->integer('odometer_km') : null,
            ));

            return $this->accepted(['plan_id' => $planId]);
        });
    }
}
