<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Logistics\FleetOps\Application\Command\RegisterDeparture\RegisterDeparture;
use Logistics\FleetOps\Presentation\Http\ApiController;
use Logistics\FleetOps\Presentation\Http\Request\RegisterDepartureRequest;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Registra la salida/despacho de un vehículo de flota propia (comando RegisterDeparture).
 *
 * Los ayudantes llegan como lista de ids; la carga como forma primitiva {description?, weight_kg?, items[]}.
 */
final class RegisterDepartureController extends ApiController
{
    public function __construct(
        private readonly CommandBus $commands,
    ) {}

    public function __invoke(RegisterDepartureRequest $request): JsonResponse
    {
        return $this->guard(function () use ($request): JsonResponse {
            /** @var list<string> $helperIds */
            $helperIds = array_values($request->array('helper_ids'));

            /** @var array{description?: string|null, weight_kg?: float|int|null, items?: list<array{name: string, qty: int}>} $load */
            $load = $request->array('load');

            $this->commands->dispatch(new RegisterDeparture(
                vehicleId: $request->string('vehicle_id')->toString(),
                driverId: $request->filled('driver_id') ? $request->string('driver_id')->toString() : null,
                helperIds: $helperIds,
                destination: $request->filled('destination') ? $request->string('destination')->toString() : null,
                routeRef: $request->filled('route_ref') ? $request->string('route_ref')->toString() : null,
                load: $load,
                odometerOutKm: $request->filled('odometer_out_km') ? $request->integer('odometer_out_km') : null,
                departedAt: $request->string('departed_at')->toString(),
                note: $request->filled('note') ? $request->string('note')->toString() : null,
            ));

            return $this->accepted(['vehicle_id' => $request->string('vehicle_id')->toString()]);
        });
    }
}
