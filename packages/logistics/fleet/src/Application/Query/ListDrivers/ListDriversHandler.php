<?php

declare(strict_types=1);

namespace Logistics\Fleet\Application\Query\ListDrivers;

use Logistics\Fleet\Application\ReadModel\DriverView;
use Logistics\Fleet\Domain\Contract\DriverRepository;
use Logistics\Fleet\Domain\Model\Driver;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;

/**
 * Devuelve la lista de choferes como proyecciones de lectura.
 *
 * @implements QueryHandler<ListDrivers, list<DriverView>>
 */
final readonly class ListDriversHandler implements QueryHandler
{
    public function __construct(
        private DriverRepository $drivers,
    ) {}

    /**
     * @return list<DriverView>
     */
    public function handle(Query $query): array
    {
        assert($query instanceof ListDrivers);

        $drivers = $this->drivers->all();
        if ($query->onlyActive === true) {
            $drivers = array_values(array_filter(
                $drivers,
                static fn (Driver $driver): bool => $driver->isActive(),
            ));
        }

        return array_map(static fn (Driver $driver): DriverView => DriverView::fromDomain($driver), $drivers);
    }
}
