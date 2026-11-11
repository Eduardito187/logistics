<?php

declare(strict_types=1);

namespace Logistics\Tracking\Infrastructure\Outbox;

use Logistics\Outbox\Application\Contract\MessageDispatcher;
use Logistics\Outbox\Domain\OutboxMessage;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Tracking\Application\Command\OpenTracking\OpenTracking;

/**
 * Reactor outbox: al crear la orden (`orders.order_placed`) abre el seguimiento del pedido en estado
 * «pendiente» (por `orderReference` = id de la orden), aún sin envío. Idempotente (OpenTracking no duplica
 * por orderReference). Es el inicio del timeline de cara al cliente.
 */
final readonly class OpenTrackingOnOrderPlaced implements MessageDispatcher
{
    public function __construct(
        private CommandBus $commandBus,
    ) {}

    public function dispatch(OutboxMessage $message): void
    {
        if ($message->eventName() !== 'orders.order_placed') {
            return;
        }

        $this->commandBus->dispatch(new OpenTracking(
            orderReference: $message->aggregateId(),
        ));
    }
}
