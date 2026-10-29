<?php

declare(strict_types=1);

namespace Commerce\Orders\Application\Command\PlaceOrder;

use Commerce\Orders\Domain\Contract\OrderRepository;
use Commerce\Orders\Domain\Model\Order;
use Commerce\Orders\Domain\ValueObject\OrderLine;
use Commerce\Orders\Domain\ValueObject\ShippingAddress;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;
use Logistics\Shared\Domain\ValueObject\Uuid;

/**
 * Crea la orden a partir de la instantánea del checkout, la persiste y publica sus eventos al outbox.
 *
 * @implements CommandHandler<PlaceOrder>
 */
final readonly class PlaceOrderHandler implements CommandHandler
{
    public function __construct(
        private OrderRepository $orders,
        private RecordDomainEvents $recordEvents,
    ) {}

    public function handle(Command $command): void
    {
        assert($command instanceof PlaceOrder);

        $lines = array_values(array_map(
            static fn (array $line): OrderLine => OrderLine::fromArray($line),
            $command->lines,
        ));

        $order = Order::place(
            $this->orders->nextIdentity(),
            $command->customerId !== null ? Uuid::fromString($command->customerId) : null,
            ShippingAddress::of(
                $command->customerName,
                $command->addressText,
                $command->addressReference,
                $command->phone,
                $command->email,
            ),
            $lines,
            $command->currency,
        );

        $this->orders->save($order);

        $this->recordEvents->record($order->releaseEvents(), 'orders.order');
    }
}
