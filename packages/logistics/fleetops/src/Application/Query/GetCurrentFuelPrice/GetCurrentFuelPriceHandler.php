<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Application\Query\GetCurrentFuelPrice;

use Logistics\FleetOps\Application\ReadModel\FuelPriceView;
use Logistics\FleetOps\Domain\Contract\FuelPriceRepository;
use Logistics\FleetOps\Domain\ValueObject\FuelType;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;
use Logistics\Shared\Domain\Clock\Clock;

/**
 * Devuelve el precio vigente de un tipo de combustible a la fecha actual, o null si no hay ninguno.
 *
 * El "ahora" se pide por el Clock (nunca now() global), para que las pruebas fijen el tiempo.
 *
 * @implements QueryHandler<GetCurrentFuelPrice, FuelPriceView|null>
 */
final readonly class GetCurrentFuelPriceHandler implements QueryHandler
{
    public function __construct(
        private FuelPriceRepository $prices,
        private Clock $clock,
    ) {}

    public function handle(Query $query): ?FuelPriceView
    {
        assert($query instanceof GetCurrentFuelPrice);

        $price = $this->prices->currentFor(FuelType::from($query->fuelType), $this->clock->now());

        return $price !== null ? FuelPriceView::fromDomain($price) : null;
    }
}
