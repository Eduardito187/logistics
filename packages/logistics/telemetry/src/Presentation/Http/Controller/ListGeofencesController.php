<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Logistics\Shared\Application\Query\QueryBus;
use Logistics\Telemetry\Application\Query\ListGeofences\ListGeofences;
use Logistics\Telemetry\Application\ReadModel\GeofenceView;
use Logistics\Telemetry\Presentation\Http\ApiController;

/**
 * Lista las geocercas con filtro opcional por tipo (?type) (consulta ListGeofences).
 */
final class ListGeofencesController extends ApiController
{
    public function __construct(
        private readonly QueryBus $queries,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        return $this->guard(function () use ($request): JsonResponse {
            $type = $request->query('type');

            /** @var list<GeofenceView> $geofences */
            $geofences = $this->queries->ask(new ListGeofences(
                type: is_string($type) && $type !== '' ? $type : null,
            ));

            return $this->data(
                array_map(static fn (GeofenceView $view): array => $view->toArray(), $geofences),
            );
        });
    }
}
