<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Telemetry\Application\Command\DefineGeofence\DefineGeofence;
use Logistics\Telemetry\Presentation\Http\ApiController;
use Logistics\Telemetry\Presentation\Http\Request\DefineGeofenceRequest;

/**
 * Define una geocerca nueva despachando el comando DefineGeofence.
 *
 * El id lo genera el dominio dentro del handler; la respuesta es un acuse 202. El polígono llega como
 * lista de pares {lat,lng}.
 */
final class DefineGeofenceController extends ApiController
{
    public function __construct(
        private readonly CommandBus $commands,
    ) {}

    public function __invoke(DefineGeofenceRequest $request): JsonResponse
    {
        return $this->guard(function () use ($request): JsonResponse {
            $nodeRef = $request->input('node_ref');

            $this->commands->dispatch(new DefineGeofence(
                name: $request->string('name')->toString(),
                type: $request->string('type')->toString(),
                polygon: $this->mapPolygon($request),
                nodeRef: is_string($nodeRef) && $nodeRef !== '' ? $nodeRef : null,
            ));

            return $this->accepted([
                'name' => $request->string('name')->toString(),
                'type' => $request->string('type')->toString(),
            ]);
        });
    }

    /**
     * Normaliza el polígono a la lista de pares {lat,lng} que espera el comando.
     *
     * @return list<array{lat: float, lng: float}>
     */
    private function mapPolygon(DefineGeofenceRequest $request): array
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
