<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Presentation\Http\Controller;

use App\Http\Support\ApiPagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Logistics\Shared\Application\Query\QueryBus;
use Logistics\Shared\Application\ReadModel\Page;
use Logistics\Telemetry\Application\Query\ListTrips\ListTrips;
use Logistics\Telemetry\Application\ReadModel\TripView;
use Logistics\Telemetry\Presentation\Http\ApiController;

/**
 * Lista trips con filtros opcionales por vehículo (?vehicle_id) y por día (?date=YYYY-MM-DD)
 * (consulta ListTrips).
 *
 * Tabla de crecimiento ilimitado: la paginación es OBLIGATORIA (per_page por defecto 50). El recorte
 * baja a SQL (LIMIT/OFFSET + COUNT(*) aparte); nunca se materializa la tabla completa.
 */
final class ListTripsController extends ApiController
{
    public function __construct(
        private readonly QueryBus $queries,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        return $this->guard(function () use ($request): JsonResponse {
            $vehicleId = $request->query('vehicle_id');
            $date = $request->query('date');

            /** @var Page<TripView> $page */
            $page = $this->queries->ask(new ListTrips(
                vehicleId: is_string($vehicleId) && $vehicleId !== '' ? $vehicleId : null,
                date: is_string($date) && $date !== '' ? $date : null,
                limit: ApiPagination::limit($request),
                offset: ApiPagination::offset($request),
            ));

            $items = array_map(static fn (TripView $view): array => $view->toArray(), $page->items);

            return new JsonResponse(ApiPagination::envelope($items, $page->total, $request));
        });
    }
}
