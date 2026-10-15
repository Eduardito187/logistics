<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Application\Query\ListVehicleProfiles;

use Logistics\FleetOps\Application\ReadModel\VehicleProfileView;
use Logistics\FleetOps\Domain\Contract\VehicleProfileRepository;
use Logistics\FleetOps\Domain\Model\VehicleProfile;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;

/**
 * Devuelve la lista de perfiles de vehículos como proyecciones de lectura.
 *
 * @implements QueryHandler<ListVehicleProfiles, list<VehicleProfileView>>
 */
final readonly class ListVehicleProfilesHandler implements QueryHandler
{
    public function __construct(
        private VehicleProfileRepository $profiles,
    ) {}

    /**
     * @return list<VehicleProfileView>
     */
    public function handle(Query $query): array
    {
        assert($query instanceof ListVehicleProfiles);

        return array_map(
            static fn (VehicleProfile $profile): VehicleProfileView => VehicleProfileView::fromDomain($profile),
            $this->profiles->all(),
        );
    }
}
