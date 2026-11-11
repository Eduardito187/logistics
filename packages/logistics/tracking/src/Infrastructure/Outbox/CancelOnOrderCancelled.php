<?php

declare(strict_types=1);

namespace Logistics\Tracking\Infrastructure\Outbox;

use Logistics\Outbox\Application\Contract\MessageDispatcher;
use Logistics\Outbox\Domain\OutboxMessage;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Tracking\Application\Command\AdvanceTracking\AdvanceTracking;
use Logistics\Tracking\Domain\ValueObject\VisibleStatus;

/**
 * Reactor outbox: al anularse la orden (`orders.order_cancelled`) el seguimiento pasa a «cancelada»
 * (todos los del pedido). Tolerante: si el pedido ya estaba entregado, la transición a cancelada no aplica
 * y se saltea (ese caso lo maneja la devolución, no la cancelación).
 */
final readonly class CancelOnOrderCancelled implements MessageDispatcher
{
    public function __construct(
        private CommandBus $commandBus,
    ) {}

    public function dispatch(OutboxMessage $message): void
    {
        if ($message->eventName() !== 'orders.order_cancelled') {
            return;
        }

        $this->commandBus->dispatch(new AdvanceTracking(
            status: VisibleStatus::Cancelled,
            orderReference: $message->aggregateId(),
        ));
    }
}
