<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Logistics\FleetOps\Application\Command\RecordOdometer\RecordOdometer;
use Logistics\FleetOps\Presentation\Http\ApiController;
use Logistics\FleetOps\Presentation\Http\Request\RecordOdometerRequest;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Registra una lectura de odómetro de un vehículo (comando RecordOdometer).
 *
 * Sirve a dos puertas: la app interna (fuente 'manual') y las integraciones (telemetría/externo).
 */
final class RecordOdometerController extends ApiController
{
    public function __construct(
        private readonly CommandBus $commands,
    ) {}

    public function __invoke(RecordOdometerRequest $request): JsonResponse
    {
        return $this->guard(function () use ($request): JsonResponse {
            $this->commands->dispatch(new RecordOdometer(
                vehicleId: $request->string('vehicle_id')->toString(),
                km: $request->integer('km'),
                source: $request->string('source')->toString(),
                readAt: $request->string('read_at')->toString(),
                note: $request->filled('note') ? $request->string('note')->toString() : null,
            ));

            return $this->accepted(['vehicle_id' => $request->string('vehicle_id')->toString()]);
        });
    }
}
