<?php

declare(strict_types=1);

namespace Logistics\Planning\Application\Command\SetVehicleRestrictions;

use Logistics\Planning\Domain\Contract\RoutingConstraintRepository;
use Logistics\Planning\Domain\Exception\PlanningException;
use Logistics\Planning\Domain\ValueObject\VehicleRouteProfile;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Valida el perfil con el value object del dominio (límites físicos mayores que cero, listas normalizadas)
 * y lo guarda.
 *
 * @implements CommandHandler<SetVehicleRestrictions>
 */
final readonly class SetVehicleRestrictionsHandler implements CommandHandler
{
    public function __construct(
        private RoutingConstraintRepository $constraints,
    ) {}

    public function handle(Command $command): void
    {
        if (!$command instanceof SetVehicleRestrictions) {
            throw PlanningException::because('SetVehicleRestrictionsHandler solo maneja SetVehicleRestrictions');
        }
        if (trim($command->vehicleId) === '') {
            throw PlanningException::because('el camión es obligatorio para definir sus restricciones');
        }

        $profile = VehicleRouteProfile::of(
            $command->allowedLoadTypes,
            $command->allowedZones,
            $command->forbiddenZones,
            $command->maxHeightM,
            $command->maxWeightKg,
        );

        $this->constraints->saveVehicleProfile(trim($command->vehicleId), $profile);
    }
}
