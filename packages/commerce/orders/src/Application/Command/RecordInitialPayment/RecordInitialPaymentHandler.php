<?php

declare(strict_types=1);

namespace Commerce\Orders\Application\Command\RecordInitialPayment;

use Commerce\Orders\Domain\Contract\OrderRepository;
use Commerce\Orders\Domain\Exception\OrdersException;
use Commerce\Orders\Domain\ValueObject\OrderId;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Registra el pago de la cuota inicial de una orden de crédito y, si se cumplen las condiciones del plan,
 * la promueve a procesando (emite OrderPaid). Idempotente.
 *
 * @implements CommandHandler<RecordInitialPayment>
 */
final readonly class RecordInitialPaymentHandler implements CommandHandler
{
    public function __construct(
        private OrderRepository $orders,
        private RecordDomainEvents $recordEvents,
    ) {}

    public function handle(Command $command): void
    {
        assert($command instanceof RecordInitialPayment);

        $order = $this->orders->findById(OrderId::fromString($command->orderId));
        if ($order === null) {
            throw OrdersException::because('La orden no existe.');
        }

        $order->recordInitialPayment($command->reference);

        $this->orders->save($order);

        $this->recordEvents->record($order->releaseEvents(), 'orders.order');
    }
}
