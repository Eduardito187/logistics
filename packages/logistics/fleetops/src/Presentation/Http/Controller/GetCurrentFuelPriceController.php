<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Logistics\FleetOps\Application\Query\GetCurrentFuelPrice\GetCurrentFuelPrice;
use Logistics\FleetOps\Application\ReadModel\FuelPriceView;
use Logistics\FleetOps\Presentation\Http\ApiController;
use Logistics\FleetOps\Presentation\Http\Request\GetCurrentFuelPriceRequest;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Devuelve el precio vigente (a hoy) de un tipo de combustible (consulta GetCurrentFuelPrice).
 * Responde 404 si no hay precio vigente.
 */
final class GetCurrentFuelPriceController extends ApiController
{
    public function __construct(
        private readonly QueryBus $queries,
    ) {}

    public function __invoke(GetCurrentFuelPriceRequest $request): JsonResponse
    {
        return $this->guard(function () use ($request): JsonResponse {
            /** @var FuelPriceView|null $price */
            $price = $this->queries->ask(new GetCurrentFuelPrice(
                fuelType: $request->string('fuel_type')->toString(),
            ));

            if ($price === null) {
                return $this->error('fuel_price_not_found', 'No current price found for the given fuel type.', 404);
            }

            return $this->data($price->toArray());
        });
    }
}
