<?php

declare(strict_types=1);

namespace Commerce\Orders\Application\Command\MarkOrderPaid;

use Commerce\Orders\Domain\Contract\OrderRepository;
use Commerce\Orders\Domain\Exception\OrdersException;
use Commerce\Orders\Domain\ValueObject\OrderId;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Marca la orden como pagada y publica `orders.order_paid` (que dispara el puente a fulfillment).
 * Idempotente a nivel de agregado.
 *
 * @implements CommandHandler<MarkOrderPaid>
 */
final readonly class MarkOrderPaidHandler implements CommandHandler
{
    public function __construct(
        private OrderRepository $orders,
        private RecordDomainEvents $recordEvents,
    ) {}

    public function handle(Command $command): void
    {
        assert($command instanceof MarkOrderPaid);

        $order = $this->orders->findById(OrderId::fromString($command->orderId));
        if ($order === null) {
            throw OrdersException::because('La orden a pagar no existe.');
        }

        $order->markPaid($command->gatewayRef);

        $this->orders->save($order);

        $this->recordEvents->record($order->releaseEvents(), 'orders.order');
    }
}
