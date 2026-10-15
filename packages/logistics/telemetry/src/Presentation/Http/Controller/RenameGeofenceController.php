<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Telemetry\Application\Command\RenameGeofence\RenameGeofence;
use Logistics\Telemetry\Presentation\Http\ApiController;
use Logistics\Telemetry\Presentation\Http\Request\RenameGeofenceRequest;

/**
 * Renombra una geocerca existente (comando RenameGeofence). El id llega por la ruta.
 */
final class RenameGeofenceController extends ApiController
{
    public function __construct(
        private readonly CommandBus $commands,
    ) {}

    public function __invoke(RenameGeofenceRequest $request, string $geofenceId): JsonResponse
    {
        return $this->guard(function () use ($request, $geofenceId): JsonResponse {
            $this->commands->dispatch(new RenameGeofence(
                geofenceId: $geofenceId,
                name: $request->string('name')->toString(),
            ));

            return $this->accepted(['geofence_id' => $geofenceId]);
        });
    }
}
