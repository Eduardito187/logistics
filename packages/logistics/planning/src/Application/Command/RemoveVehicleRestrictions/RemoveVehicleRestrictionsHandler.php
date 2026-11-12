<?php

declare(strict_types=1);

namespace Logistics\Planning\Application\Command\RemoveVehicleRestrictions;

use Logistics\Planning\Domain\Contract\RoutingConstraintRepository;
use Logistics\Planning\Domain\Exception\PlanningException;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Quita el perfil de restricciones del camión (idempotente).
 *
 * @implements CommandHandler<RemoveVehicleRestrictions>
 */
final readonly class RemoveVehicleRestrictionsHandler implements CommandHandler
{
    public function __construct(
        private RoutingConstraintRepository $constraints,
    ) {}

    public function handle(Command $command): void
    {
        if (!$command instanceof RemoveVehicleRestrictions) {
            throw PlanningException::because('RemoveVehicleRestrictionsHandler solo maneja RemoveVehicleRestrictions');
        }

        $this->constraints->removeVehicleProfile(trim($command->vehicleId));
    }
}
