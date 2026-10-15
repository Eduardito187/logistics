<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Telemetry\Application\Command\DeleteGeofence\DeleteGeofence;
use Logistics\Telemetry\Presentation\Http\ApiController;

/**
 * Elimina una geocerca (comando DeleteGeofence). El id llega por la ruta.
 */
final class DeleteGeofenceController extends ApiController
{
    public function __construct(
        private readonly CommandBus $commands,
    ) {}

    public function __invoke(string $geofenceId): JsonResponse
    {
        return $this->guard(function () use ($geofenceId): JsonResponse {
            $this->commands->dispatch(new DeleteGeofence(geofenceId: $geofenceId));

            return $this->accepted(['geofence_id' => $geofenceId]);
        });
    }
}
