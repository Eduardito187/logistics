<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Telemetry\Application\Command\RedrawGeofence\RedrawGeofence;
use Logistics\Telemetry\Presentation\Http\ApiController;
use Logistics\Telemetry\Presentation\Http\Request\RedrawGeofenceRequest;

/**
 * Redibuja el polígono de una geocerca existente (comando RedrawGeofence). El id llega por la ruta.
 */
final class RedrawGeofenceController extends ApiController
{
    public function __construct(
        private readonly CommandBus $commands,
    ) {}

    public function __invoke(RedrawGeofenceRequest $request, string $geofenceId): JsonResponse
    {
        return $this->guard(function () use ($request, $geofenceId): JsonResponse {
            $this->commands->dispatch(new RedrawGeofence(
                geofenceId: $geofenceId,
                polygon: $this->mapPolygon($request),
            ));

            return $this->accepted(['geofence_id' => $geofenceId]);
        });
    }

    /**
     * Normaliza el polígono a la lista de pares {lat,lng} que espera el comando.
     *
     * @return list<array{lat: float, lng: float}>
     */
    private function mapPolygon(RedrawGeofenceRequest $request): array
    {
        $polygon = [];

        foreach ($request->array('polygon') as $vertex) {
            if (!is_array($vertex)) {
                continue;
            }

            $polygon[] = [
                'lat' => (float) $vertex['lat'],
                'lng' => (float) $vertex['lng'],
            ];
        }

        return $polygon;
    }
}
