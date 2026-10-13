<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Application\Command\CompleteMaintenance;

use Logistics\FleetOps\Domain\Contract\MaintenanceOrderRepository;
use Logistics\FleetOps\Domain\Exception\FleetOpsException;
use Logistics\FleetOps\Domain\ValueObject\MaintenanceItem;
use Logistics\FleetOps\Domain\ValueObject\MaintenanceOrderId;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;
use Logistics\Shared\Domain\Clock\Clock;

/**
 * Carga la orden, la completa con sus ítems (convertidos a MaintenanceItem con la moneda del comando),
 * la persiste y publica al outbox. Falla si no existe.
 *
 * @implements CommandHandler<CompleteMaintenance>
 */
final readonly class CompleteMaintenanceHandler implements CommandHandler
{
    public function __construct(
        private MaintenanceOrderRepository $orders,
        private Clock $clock,
        private RecordDomainEvents $recordEvents,
    ) {}

    public function handle(Command $command): void
    {
        assert($command instanceof CompleteMaintenance);

        $order = $this->orders->findById(MaintenanceOrderId::fromString($command->orderId));
        if ($order === null) {
            throw FleetOpsException::because("No existe la orden de mantenimiento {$command->orderId}.");
        }

        $items = [];
        foreach ($command->items as $item) {
            $items[] = MaintenanceItem::fromArray($item, $command->currency);
        }

        $order->complete($items, $this->clock->now());

        $this->orders->save($order);

        $this->recordEvents->record($order->releaseEvents(), 'fleetops.maintenance_order');
    }
}
