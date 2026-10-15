<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Application\Command\StartMaintenance;

use Logistics\FleetOps\Domain\Contract\MaintenanceOrderRepository;
use Logistics\FleetOps\Domain\Exception\FleetOpsException;
use Logistics\FleetOps\Domain\ValueObject\MaintenanceOrderId;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;
use Logistics\Shared\Domain\Clock\Clock;

/**
 * Carga la orden, la inicia (vía Clock para el instante), la persiste y publica al outbox.
 * Falla si no existe.
 *
 * @implements CommandHandler<StartMaintenance>
 */
final readonly class StartMaintenanceHandler implements CommandHandler
{
    public function __construct(
        private MaintenanceOrderRepository $orders,
        private Clock $clock,
        private RecordDomainEvents $recordEvents,
    ) {}

    public function handle(Command $command): void
    {
        assert($command instanceof StartMaintenance);

        $order = $this->orders->findById(MaintenanceOrderId::fromString($command->orderId));
        if ($order === null) {
            throw FleetOpsException::because("No existe la orden de mantenimiento {$command->orderId}.");
        }

        $order->start($this->clock->now());

        $this->orders->save($order);

        $this->recordEvents->record($order->releaseEvents(), 'fleetops.maintenance_order');
    }
}
