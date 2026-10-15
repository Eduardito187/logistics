<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Application\Command\ActivatePlan;

use Logistics\FleetOps\Domain\Contract\MaintenancePlanRepository;
use Logistics\FleetOps\Domain\Exception\FleetOpsException;
use Logistics\FleetOps\Domain\ValueObject\MaintenancePlanId;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Carga el plan, lo activa, lo persiste y publica al outbox. Falla si no existe.
 *
 * @implements CommandHandler<ActivatePlan>
 */
final readonly class ActivatePlanHandler implements CommandHandler
{
    public function __construct(
        private MaintenancePlanRepository $plans,
        private RecordDomainEvents $recordEvents,
    ) {}

    public function handle(Command $command): void
    {
        assert($command instanceof ActivatePlan);

        $plan = $this->plans->findById(MaintenancePlanId::fromString($command->planId));
        if ($plan === null) {
            throw FleetOpsException::because("No existe el plan de mantenimiento {$command->planId}.");
        }

        $plan->activate();

        $this->plans->save($plan);

        $this->recordEvents->record($plan->releaseEvents(), 'fleetops.maintenance_plan');
    }
}
