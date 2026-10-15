<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Application\Command\CancelMaintenance;

use Logistics\FleetOps\Domain\Contract\MaintenanceOrderRepository;
use Logistics\FleetOps\Domain\Exception\FleetOpsException;
use Logistics\FleetOps\Domain\ValueObject\MaintenanceOrderId;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;
use Logistics\Shared\Domain\Clock\Clock;

/**
 * Carga la orden, la cancela (vía Clock para el instante), la persiste y publica al outbox.
 * Falla si no existe.
 *
 * @implements CommandHandler<CancelMaintenance>
 */
final readonly class CancelMaintenanceHandler implements CommandHandler
{
    public function __construct(
        private MaintenanceOrderRepository $orders,
        private Clock $clock,
        private RecordDomainEvents $recordEvents,
    ) {}

    public function handle(Command $command): void
    {
        assert($command instanceof CancelMaintenance);

        $order = $this->orders->findById(MaintenanceOrderId::fromString($command->orderId));
        if ($order === null) {
            throw FleetOpsException::because("No existe la orden de mantenimiento {$command->orderId}.");
        }

        $order->cancel($this->clock->now());

        $this->orders->save($order);

        $this->recordEvents->record($order->releaseEvents(), 'fleetops.maintenance_order');
    }
}
