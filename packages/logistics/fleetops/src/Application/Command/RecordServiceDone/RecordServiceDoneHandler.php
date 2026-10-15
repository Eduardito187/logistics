<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Application\Command\RecordServiceDone;

use Logistics\FleetOps\Domain\Contract\MaintenancePlanRepository;
use Logistics\FleetOps\Domain\Exception\FleetOpsException;
use Logistics\FleetOps\Domain\ValueObject\MaintenancePlanId;
use Logistics\FleetOps\Domain\ValueObject\Odometer;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;
use Logistics\Shared\Domain\Clock\Clock;

/**
 * Carga el plan, registra el servicio ejecutado (vía Clock para el instante), lo persiste y publica al
 * outbox. Falla si no existe.
 *
 * @implements CommandHandler<RecordServiceDone>
 */
final readonly class RecordServiceDoneHandler implements CommandHandler
{
    public function __construct(
        private MaintenancePlanRepository $plans,
        private Clock $clock,
        private RecordDomainEvents $recordEvents,
    ) {}

    public function handle(Command $command): void
    {
        assert($command instanceof RecordServiceDone);

        $plan = $this->plans->findById(MaintenancePlanId::fromString($command->planId));
        if ($plan === null) {
            throw FleetOpsException::because("No existe el plan de mantenimiento {$command->planId}.");
        }

        $plan->recordServiceDone(
            $command->odometerKm !== null ? Odometer::km($command->odometerKm) : null,
            $this->clock->now(),
        );

        $this->plans->save($plan);

        $this->recordEvents->record($plan->releaseEvents(), 'fleetops.maintenance_plan');
    }
}
