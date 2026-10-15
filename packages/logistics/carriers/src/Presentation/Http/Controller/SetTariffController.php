<?php

declare(strict_types=1);

namespace Logistics\Carriers\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Logistics\Carriers\Application\Command\SetTariff\SetTariff;
use Logistics\Carriers\Presentation\Http\ApiController;
use Logistics\Carriers\Presentation\Http\Request\SetTariffRequest;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Define la tarifa de un transportista para un corredor y clase de vehículo (comando SetTariff).
 *
 * El id del transportista llega por la ruta; el corredor, la clase, el precio (centavos + moneda) y la
 * vigencia por el cuerpo.
 */
final class SetTariffController extends ApiController
{
    public function __construct(
        private readonly CommandBus $commands,
    ) {}

    public function __invoke(SetTariffRequest $request, string $carrierId): JsonResponse
    {
        return $this->guard(function () use ($request, $carrierId): JsonResponse {
            $this->commands->dispatch(new SetTariff(
                carrierId: $carrierId,
                corridorFrom: $request->string('corridor_from')->toString(),
                corridorTo: $request->string('corridor_to')->toString(),
                corridorType: $request->string('corridor_type')->toString(),
                vehicleClass: $request->string('vehicle_class')->toString(),
                priceCents: $request->integer('price_cents'),
                currency: $request->string('currency')->toString(),
                effectiveFrom: $request->string('effective_from')->toString(),
            ));

            return $this->accepted(['carrier_id' => $carrierId]);
        });
    }
}
