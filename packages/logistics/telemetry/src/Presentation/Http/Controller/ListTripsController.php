<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Logistics\Shared\Application\Query\QueryBus;
use Logistics\Telemetry\Application\Query\ListTrips\ListTrips;
use Logistics\Telemetry\Application\ReadModel\TripView;
use Logistics\Telemetry\Presentation\Http\ApiController;

/**
 * Lista trips con filtros opcionales por vehículo (?vehicle_id) y por día (?date=YYYY-MM-DD)
 * (consulta ListTrips).
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

            /** @var list<TripView> $trips */
            $trips = $this->queries->ask(new ListTrips(
                vehicleId: is_string($vehicleId) && $vehicleId !== '' ? $vehicleId : null,
                date: is_string($date) && $date !== '' ? $date : null,
            ));

            return $this->data(
                array_map(static fn (TripView $view): array => $view->toArray(), $trips),
            );
        });
    }
}
