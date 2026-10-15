<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Logistics\FleetOps\Application\Command\SetFuelPrice\SetFuelPrice;
use Logistics\FleetOps\Presentation\Http\ApiController;
use Logistics\FleetOps\Presentation\Http\Request\SetFuelPriceRequest;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Fija el precio por litro de un tipo de combustible vigente desde una fecha (comando SetFuelPrice).
 *
 * El dinero llega como centavos + moneda; la respuesta es un acuse 202 (escritura asíncrona por outbox).
 */
final class SetFuelPriceController extends ApiController
{
    public function __construct(
        private readonly CommandBus $commands,
    ) {}

    public function __invoke(SetFuelPriceRequest $request): JsonResponse
    {
        return $this->guard(function () use ($request): JsonResponse {
            $this->commands->dispatch(new SetFuelPrice(
                fuelType: $request->string('fuel_type')->toString(),
                priceCents: $request->integer('price_cents'),
                currency: $request->string('currency')->toString(),
                effectiveFrom: $request->string('effective_from')->toString(),
                source: $request->filled('source') ? $request->string('source')->toString() : null,
            ));

            return $this->accepted([
                'fuel_type' => $request->string('fuel_type')->toString(),
                'effective_from' => $request->string('effective_from')->toString(),
            ]);
        });
    }
}
