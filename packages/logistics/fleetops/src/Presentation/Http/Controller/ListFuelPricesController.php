<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Logistics\FleetOps\Application\Query\ListFuelPrices\ListFuelPrices;
use Logistics\FleetOps\Application\ReadModel\FuelPriceView;
use Logistics\FleetOps\Presentation\Http\ApiController;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Lista los precios de combustible registrados, con filtro opcional por tipo (?fuel_type=).
 */
final class ListFuelPricesController extends ApiController
{
    public function __construct(
        private readonly QueryBus $queries,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        return $this->guard(function () use ($request): JsonResponse {
            $fuelType = $request->query('fuel_type');
            $fuelType = is_string($fuelType) && $fuelType !== '' ? $fuelType : null;

            /** @var list<FuelPriceView> $prices */
            $prices = $this->queries->ask(new ListFuelPrices(fuelType: $fuelType));

            return $this->data(
                array_map(static fn (FuelPriceView $view): array => $view->toArray(), $prices),
            );
        });
    }
}
