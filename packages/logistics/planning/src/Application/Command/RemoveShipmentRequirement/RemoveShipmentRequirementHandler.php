<?php

declare(strict_types=1);

namespace Logistics\Planning\Application\Command\RemoveShipmentRequirement;

use Logistics\Planning\Domain\Contract\RoutingConstraintRepository;
use Logistics\Planning\Domain\Exception\PlanningException;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Quita los requisitos de ruteo del envío (idempotente).
 *
 * @implements CommandHandler<RemoveShipmentRequirement>
 */
final readonly class RemoveShipmentRequirementHandler implements CommandHandler
{
    public function __construct(
        private RoutingConstraintRepository $constraints,
    ) {}

    public function handle(Command $command): void
    {
        if (!$command instanceof RemoveShipmentRequirement) {
            throw PlanningException::because('RemoveShipmentRequirementHandler solo maneja RemoveShipmentRequirement');
        }

        $this->constraints->removeShipmentRequirement(trim($command->shipmentId));
    }
}
