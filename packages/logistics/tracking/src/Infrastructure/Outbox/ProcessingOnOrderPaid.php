<?php

declare(strict_types=1);

namespace Logistics\Tracking\Infrastructure\Outbox;

use Logistics\Outbox\Application\Contract\MessageDispatcher;
use Logistics\Outbox\Domain\OutboxMessage;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Tracking\Application\Command\AdvanceTracking\AdvanceTracking;
use Logistics\Tracking\Domain\ValueObject\VisibleStatus;

/**
 * Reactor outbox: al pagarse la orden (`orders.order_paid`) el seguimiento del pedido pasa a «procesando»
 * (todos los seguimientos del pedido, por `orderReference`). Tolerante e idempotente.
 */
final readonly class ProcessingOnOrderPaid implements MessageDispatcher
{
    public function __construct(
        private CommandBus $commandBus,
    ) {}

    public function dispatch(OutboxMessage $message): void
    {
        if ($message->eventName() !== 'orders.order_paid') {
            return;
        }

        $this->commandBus->dispatch(new AdvanceTracking(
            status: VisibleStatus::Processing,
            orderReference: $message->aggregateId(),
        ));
    }
}
