<?php

declare(strict_types=1);

namespace Logistics\Fleet\Application\Query\ListVehicles;

use Logistics\Fleet\Application\ReadModel\VehicleView;
use Logistics\Fleet\Domain\Contract\VehicleRepository;
use Logistics\Fleet\Domain\Model\Vehicle;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;

/**
 * Devuelve la lista de vehículos como proyecciones de lectura.
 *
 * @implements QueryHandler<ListVehicles, list<VehicleView>>
 */
final readonly class ListVehiclesHandler implements QueryHandler
{
    public function __construct(
        private VehicleRepository $vehicles,
    ) {}

    /**
     * @return list<VehicleView>
     */
    public function handle(Query $query): array
    {
        assert($query instanceof ListVehicles);

        $vehicles = $this->vehicles->all();
        if ($query->status !== null) {
            $vehicles = array_values(array_filter(
                $vehicles,
                static fn (Vehicle $vehicle): bool => $vehicle->status()->value === $query->status,
            ));
        }

        return array_map(static fn (Vehicle $vehicle): VehicleView => VehicleView::fromDomain($vehicle), $vehicles);
    }
}
