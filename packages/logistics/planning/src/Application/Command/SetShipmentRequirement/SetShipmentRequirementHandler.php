<?php

declare(strict_types=1);

namespace Logistics\Planning\Application\Command\SetShipmentRequirement;

use Logistics\Planning\Domain\Contract\RoutingConstraintRepository;
use Logistics\Planning\Domain\Exception\PlanningException;
use Logistics\Planning\Domain\ValueObject\ShipmentRouteRequirement;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Valida el requisito con el value object del dominio (dimensiones no negativas) y lo guarda.
 *
 * @implements CommandHandler<SetShipmentRequirement>
 */
final readonly class SetShipmentRequirementHandler implements CommandHandler
{
    public function __construct(
        private RoutingConstraintRepository $constraints,
    ) {}

    public function handle(Command $command): void
    {
        if (!$command instanceof SetShipmentRequirement) {
            throw PlanningException::because('SetShipmentRequirementHandler solo maneja SetShipmentRequirement');
        }
        if (trim($command->shipmentId) === '') {
            throw PlanningException::because('el envío es obligatorio para definir sus requisitos');
        }

        $requirement = ShipmentRouteRequirement::of(
            $command->loadType,
            $command->zone,
            $command->heightM,
            $command->weightKg,
        );

        $this->constraints->saveShipmentRequirement(trim($command->shipmentId), $requirement);
    }
}
