<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Logistics\FleetOps\Application\Query\ListOdometerReadings\ListOdometerReadings;
use Logistics\FleetOps\Application\ReadModel\OdometerView;
use Logistics\FleetOps\Presentation\Http\ApiController;
use Logistics\FleetOps\Presentation\Http\Request\ListOdometerReadingsRequest;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Lista lecturas de odómetro con filtros opcionales por vehículo y rango de fecha (ListOdometerReadings).
 */
final class ListOdometerReadingsController extends ApiController
{
    public function __construct(
        private readonly QueryBus $queries,
    ) {}

    public function __invoke(ListOdometerReadingsRequest $request): JsonResponse
    {
        return $this->guard(function () use ($request): JsonResponse {
            /** @var list<OdometerView> $readings */
            $readings = $this->queries->ask(new ListOdometerReadings(
                vehicleId: $request->filled('vehicle_id') ? $request->string('vehicle_id')->toString() : null,
                from: $request->filled('from') ? $request->string('from')->toString() : null,
                to: $request->filled('to') ? $request->string('to')->toString() : null,
            ));

            return $this->data(
                array_map(static fn (OdometerView $view): array => $view->toArray(), $readings),
            );
        });
    }
}
