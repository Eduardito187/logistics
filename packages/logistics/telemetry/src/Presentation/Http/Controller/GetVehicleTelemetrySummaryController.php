<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Logistics\Shared\Application\Query\QueryBus;
use Logistics\Telemetry\Application\Query\GetVehicleTelemetrySummary\GetVehicleTelemetrySummary;
use Logistics\Telemetry\Application\ReadModel\VehicleSummaryView;
use Logistics\Telemetry\Presentation\Http\ApiController;

/**
 * Devuelve el resumen de telemetría de un vehículo en un día (consulta GetVehicleTelemetrySummary).
 *
 * `date` llega por query como YYYY-MM-DD; si falta, se asume el día de hoy.
 */
final class GetVehicleTelemetrySummaryController extends ApiController
{
    public function __construct(
        private readonly QueryBus $queries,
    ) {}

    public function __invoke(Request $request, string $vehicleId): JsonResponse
    {
        return $this->guard(function () use ($request, $vehicleId): JsonResponse {
            $date = $request->query('date');
            $date = is_string($date) && $date !== '' ? $date : date('Y-m-d');

            /** @var VehicleSummaryView $summary */
            $summary = $this->queries->ask(new GetVehicleTelemetrySummary(
                vehicleId: $vehicleId,
                date: $date,
            ));

            return $this->data($summary->toArray());
        });
    }
}
