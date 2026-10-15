<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Application\Command\DefineMaintenancePlan;

use Logistics\FleetOps\Domain\Contract\MaintenancePlanRepository;
use Logistics\FleetOps\Domain\Model\MaintenancePlan;
use Logistics\FleetOps\Domain\ValueObject\ServiceKind;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Define un plan de mantenimiento preventivo, lo persiste y publica sus eventos al outbox.
 *
 * @implements CommandHandler<DefineMaintenancePlan>
 */
final readonly class DefineMaintenancePlanHandler implements CommandHandler
{
    public function __construct(
        private MaintenancePlanRepository $plans,
        private RecordDomainEvents $recordEvents,
    ) {}

    public function handle(Command $command): void
    {
        assert($command instanceof DefineMaintenancePlan);

        $plan = MaintenancePlan::define(
            $this->plans->nextIdentity(),
            $command->vehicleId,
            ServiceKind::from($command->serviceKind),
            $command->intervalKm,
            $command->intervalDays,
        );

        $this->plans->save($plan);

        $this->recordEvents->record($plan->releaseEvents(), 'fleetops.maintenance_plan');
    }
}
