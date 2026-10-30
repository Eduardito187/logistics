<?php

declare(strict_types=1);

namespace Commerce\Orders\Infrastructure\Dispatch;

use Commerce\Orders\Application\Command\MarkOrderPaid\MarkOrderPaid;
use Logistics\Outbox\Application\Contract\MessageDispatcher;
use Logistics\Outbox\Domain\OutboxMessage;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Puente payments → orders por outbox: cuando se confirma un cobro (`payments.captured`), marca la
 * orden como pagada. Consume el evento por su NOMBRE y PAYLOAD, sin `use` de clases de payments (así
 * cada contexto queda extraíble a microservicio). Idempotente: `MarkOrderPaid` lo es en el agregado.
 */
final readonly class PaymentCapturedOrderDispatcher implements MessageDispatcher
{
    public function __construct(
        private CommandBus $commandBus,
    ) {}

    public function dispatch(OutboxMessage $message): void
    {
        if ($message->eventName() !== 'payments.captured') {
            return;
        }

        $payload = $message->payload();
        $orderId = $payload['order_id'] ?? null;
        $gatewayRef = $payload['gateway_ref'] ?? null;

        if (!is_string($orderId) || $orderId === '') {
            return;
        }

        $this->commandBus->dispatch(new MarkOrderPaid(
            $orderId,
            is_string($gatewayRef) ? $gatewayRef : '',
        ));
    }
}
