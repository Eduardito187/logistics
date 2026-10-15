<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Application\Query\GetVehicleProfile;

use Logistics\FleetOps\Application\ReadModel\VehicleProfileView;
use Logistics\FleetOps\Domain\Contract\VehicleProfileRepository;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;

/**
 * Devuelve el perfil de un vehículo, o null si no existe.
 *
 * @implements QueryHandler<GetVehicleProfile, VehicleProfileView|null>
 */
final readonly class GetVehicleProfileHandler implements QueryHandler
{
    public function __construct(
        private VehicleProfileRepository $profiles,
    ) {}

    public function handle(Query $query): ?VehicleProfileView
    {
        assert($query instanceof GetVehicleProfile);

        $profile = $this->profiles->findByVehicle($query->vehicleId);

        return $profile === null ? null : VehicleProfileView::fromDomain($profile);
    }
}
