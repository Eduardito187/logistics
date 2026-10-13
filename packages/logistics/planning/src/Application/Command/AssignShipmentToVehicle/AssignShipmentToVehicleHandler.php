<?php

declare(strict_types=1);

namespace Logistics\Planning\Application\Command\AssignShipmentToVehicle;

use Logistics\Planning\Domain\Contract\PlanRepository;
use Logistics\Planning\Domain\Exception\PlanningException;
use Logistics\Planning\Domain\ValueObject\PlanId;
use Logistics\Planning\Domain\ValueObject\PlannedStopId;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Carga el plan, le agrega el envío, lo persiste y libera sus eventos.
 *
 * @implements CommandHandler<AssignShipmentToVehicle>
 */
final readonly class AssignShipmentToVehicleHandler implements CommandHandler
{
    public function __construct(private PlanRepository $plans) {}

    public function handle(Command $command): void
    {
        if (!$command instanceof AssignShipmentToVehicle) {
            throw PlanningException::because('AssignShipmentToVehicleHandler solo maneja AssignShipmentToVehicle');
        }

        $plan = $this->plans->findById(PlanId::fromString($command->planId))
            ?? throw PlanningException::because("no existe el plan {$command->planId}");

        $plan->assignShipment(PlannedStopId::generate(), $command->shipmentId);

        $this->plans->save($plan);

        // TODO(outbox): despachar al outbox dentro de la misma transacción al integrar.
        $plan->releaseEvents();
    }
}
