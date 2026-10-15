<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Logistics\FleetOps\Application\Query\ListDepartures\ListDepartures;
use Logistics\FleetOps\Application\ReadModel\DepartureView;
use Logistics\FleetOps\Presentation\Http\ApiController;
use Logistics\FleetOps\Presentation\Http\Request\ListDeparturesRequest;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Lista salidas con filtros opcionales por vehículo, estado y rango de fecha de salida (ListDepartures).
 */
final class ListDeparturesController extends ApiController
{
    public function __construct(
        private readonly QueryBus $queries,
    ) {}

    public function __invoke(ListDeparturesRequest $request): JsonResponse
    {
        return $this->guard(function () use ($request): JsonResponse {
            /** @var list<DepartureView> $departures */
            $departures = $this->queries->ask(new ListDepartures(
                vehicleId: $request->filled('vehicle_id') ? $request->string('vehicle_id')->toString() : null,
                status: $request->filled('status') ? $request->string('status')->toString() : null,
                from: $request->filled('from') ? $request->string('from')->toString() : null,
                to: $request->filled('to') ? $request->string('to')->toString() : null,
            ));

            return $this->data(
                array_map(static fn (DepartureView $view): array => $view->toArray(), $departures),
            );
        });
    }
}
