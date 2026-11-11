<?php

declare(strict_types=1);

namespace Commerce\Orders\Application\Command\SignOrderContract;

use Commerce\Orders\Domain\Contract\OrderRepository;
use Commerce\Orders\Domain\Exception\OrdersException;
use Commerce\Orders\Domain\ValueObject\OrderId;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Registra la firma del contrato de crédito de una orden y, si se cumplen las condiciones del plan, la
 * promueve a procesando (emite OrderPaid). Idempotente.
 *
 * @implements CommandHandler<SignOrderContract>
 */
final readonly class SignOrderContractHandler implements CommandHandler
{
    public function __construct(
        private OrderRepository $orders,
        private RecordDomainEvents $recordEvents,
    ) {}

    public function handle(Command $command): void
    {
        assert($command instanceof SignOrderContract);

        $order = $this->orders->findById(OrderId::fromString($command->orderId));
        if ($order === null) {
            throw OrdersException::because('La orden no existe.');
        }

        $order->signContract($command->reference);

        $this->orders->save($order);

        $this->recordEvents->record($order->releaseEvents(), 'orders.order');
    }
}
