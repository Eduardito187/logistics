<?php

declare(strict_types=1);

namespace Logistics\Tracking\Infrastructure\Outbox;

use Logistics\Outbox\Application\Contract\MessageDispatcher;
use Logistics\Outbox\Domain\OutboxMessage;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Tracking\Application\Command\AdvanceTracking\AdvanceTracking;
use Logistics\Tracking\Domain\ValueObject\VisibleStatus;

/**
 * Reactor outbox: al generarse las etiquetas del pedido (`labels.labels_emitted`) el seguimiento pasa a
 * «en curso». El `order_number` de la etiqueta es el external_id del envío (orderId o orderId#n:nodeId): se
 * deriva el `orderReference` quitando «#n:» y se avanza el/los seguimiento(s) del pedido. Tolerante.
 */
final readonly class InProgressOnLabelsEmitted implements MessageDispatcher
{
    public function __construct(
        private CommandBus $commandBus,
    ) {}

    public function dispatch(OutboxMessage $message): void
    {
        if ($message->eventName() !== 'labels.labels_emitted') {
            return;
        }

        $payload = $message->payload();
        $orderNumber = is_string($payload['order_number'] ?? null) ? $payload['order_number'] : $message->aggregateId();
        if ($orderNumber === '') {
            return;
        }

        $this->commandBus->dispatch(new AdvanceTracking(
            status: VisibleStatus::InProgress,
            orderReference: explode('#n:', $orderNumber)[0],
        ));
    }
}
