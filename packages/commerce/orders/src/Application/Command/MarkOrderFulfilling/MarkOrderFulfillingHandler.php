<?php

declare(strict_types=1);

namespace Commerce\Orders\Application\Command\MarkOrderFulfilling;

use Commerce\Orders\Domain\Contract\OrderRepository;
use Commerce\Orders\Domain\Exception\OrdersException;
use Commerce\Orders\Domain\ValueObject\OrderId;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Pasa la orden a Fulfilling. Idempotente (el agregado no re-graba si ya se solicitó el envío).
 *
 * @implements CommandHandler<MarkOrderFulfilling>
 */
final readonly class MarkOrderFulfillingHandler implements CommandHandler
{
    public function __construct(
        private OrderRepository $orders,
        private RecordDomainEvents $recordEvents,
    ) {}

    public function handle(Command $command): void
    {
        assert($command instanceof MarkOrderFulfilling);

        $order = $this->orders->findById(OrderId::fromString($command->orderId));
        if ($order === null) {
            throw OrdersException::because('La orden a poner en fulfillment no existe.');
        }

        $order->markFulfilling();

        $this->orders->save($order);

        $this->recordEvents->record($order->releaseEvents(), 'orders.order');
    }
}
