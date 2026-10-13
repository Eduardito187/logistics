<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Application\Query\ListFuelPrices;

use Logistics\FleetOps\Application\ReadModel\FuelPriceView;
use Logistics\FleetOps\Domain\Contract\FuelPriceRepository;
use Logistics\FleetOps\Domain\Model\FuelPrice;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;

/**
 * Devuelve la lista de precios de combustible como proyecciones de lectura.
 *
 * @implements QueryHandler<ListFuelPrices, list<FuelPriceView>>
 */
final readonly class ListFuelPricesHandler implements QueryHandler
{
    public function __construct(
        private FuelPriceRepository $prices,
    ) {}

    /**
     * @return list<FuelPriceView>
     */
    public function handle(Query $query): array
    {
        assert($query instanceof ListFuelPrices);

        $prices = $this->prices->all();
        if ($query->fuelType !== null) {
            $prices = array_values(array_filter(
                $prices,
                static fn (FuelPrice $price): bool => $price->fuelType()->value === $query->fuelType,
            ));
        }

        return array_map(static fn (FuelPrice $price): FuelPriceView => FuelPriceView::fromDomain($price), $prices);
    }
}
