<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Logistics\FleetOps\Application\Command\RecordRefuel\RecordRefuel;
use Logistics\FleetOps\Presentation\Http\ApiController;
use Logistics\FleetOps\Presentation\Http\Request\RecordRefuelRequest;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Registra una carga de combustible de la flota propia (comando RecordRefuel).
 *
 * Sirve a dos puertas: la app interna y las integraciones (sistemas de tarjeta/estación). El precio por
 * litro llega como centavos + moneda; el total lo calcula el dominio (precio × litros).
 */
final class RecordRefuelController extends ApiController
{
    public function __construct(
        private readonly CommandBus $commands,
    ) {}

    public function __invoke(RecordRefuelRequest $request): JsonResponse
    {
        return $this->guard(function () use ($request): JsonResponse {
            $this->commands->dispatch(new RecordRefuel(
                vehicleId: $request->string('vehicle_id')->toString(),
                driverId: $request->filled('driver_id') ? $request->string('driver_id')->toString() : null,
                fuelType: $request->string('fuel_type')->toString(),
                liters: $request->float('liters'),
                pricePerLiterCents: $request->integer('price_per_liter_cents'),
                currency: $request->string('currency')->toString(),
                odometerKm: $request->filled('odometer_km') ? $request->integer('odometer_km') : null,
                station: $request->filled('station') ? $request->string('station')->toString() : null,
                fullTank: $request->boolean('full_tank'),
                refueledAt: $request->string('refueled_at')->toString(),
                note: $request->filled('note') ? $request->string('note')->toString() : null,
            ));

            return $this->accepted(['vehicle_id' => $request->string('vehicle_id')->toString()]);
        });
    }
}
