<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Application\Command\ScheduleMaintenance;

use DateTimeImmutable;
use Logistics\FleetOps\Domain\Contract\MaintenanceOrderRepository;
use Logistics\FleetOps\Domain\Model\MaintenanceOrder;
use Logistics\FleetOps\Domain\ValueObject\MaintenanceType;
use Logistics\FleetOps\Domain\ValueObject\Odometer;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Agenda una orden de mantenimiento, la persiste y publica sus eventos al outbox.
 *
 * El save del repositorio y el append al outbox corren dentro de la misma transacción que abre el
 * decorador transaccional del CommandBus (Infrastructure); por eso acá no hay nada del framework.
 *
 * @implements CommandHandler<ScheduleMaintenance>
 */
final readonly class ScheduleMaintenanceHandler implements CommandHandler
{
    public function __construct(
        private MaintenanceOrderRepository $orders,
        private RecordDomainEvents $recordEvents,
    ) {}

    public function handle(Command $command): void
    {
        assert($command instanceof ScheduleMaintenance);

        $order = MaintenanceOrder::schedule(
            $this->orders->nextIdentity(),
            $command->vehicleId,
            MaintenanceType::from($command->type),
            $command->odometerKm !== null ? Odometer::km($command->odometerKm) : null,
            $command->workshop,
            new DateTimeImmutable($command->scheduledAt),
            $command->note,
        );

        $this->orders->save($order);

        $this->recordEvents->record($order->releaseEvents(), 'fleetops.maintenance_order');
    }
}
