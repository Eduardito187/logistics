<?php

declare(strict_types=1);

namespace Commerce\Orders\Application\Command\CancelOrder;

use Commerce\Orders\Domain\Contract\OrderRepository;
use Commerce\Orders\Domain\Exception\OrdersException;
use Commerce\Orders\Domain\ValueObject\OrderId;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Cancela una orden pendiente. Idempotente.
 *
 * @implements CommandHandler<CancelOrder>
 */
final readonly class CancelOrderHandler implements CommandHandler
{
    public function __construct(
        private OrderRepository $orders,
        private RecordDomainEvents $recordEvents,
    ) {}

    public function handle(Command $command): void
    {
        assert($command instanceof CancelOrder);

        $order = $this->orders->findById(OrderId::fromString($command->orderId));
        if ($order === null) {
            throw OrdersException::because('La orden a cancelar no existe.');
        }

        $order->cancel();

        $this->orders->save($order);

        $this->recordEvents->record($order->releaseEvents(), 'orders.order');
    }
}
