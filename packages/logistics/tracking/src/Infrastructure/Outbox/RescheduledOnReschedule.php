<?php

declare(strict_types=1);

namespace Logistics\Tracking\Infrastructure\Outbox;

use Logistics\Outbox\Application\Contract\MessageDispatcher;
use Logistics\Outbox\Domain\OutboxMessage;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Tracking\Application\Command\AdvanceTracking\AdvanceTracking;
use Logistics\Tracking\Domain\ValueObject\VisibleStatus;

/**
 * Reactor outbox: al reprogramarse la entrega de un envío (`shipments.shipment_rescheduled`) su
 * seguimiento pasa a «reprogramada». Es por envío (shipment_id del payload). Tolerante: solo aplica tras
 * el pago y antes de entregar.
 */
final readonly class RescheduledOnReschedule implements MessageDispatcher
{
    public function __construct(
        private CommandBus $commandBus,
    ) {}

    public function dispatch(OutboxMessage $message): void
    {
        if ($message->eventName() !== 'shipments.shipment_rescheduled') {
            return;
        }

        $payload = $message->payload();
        $shipmentId = is_string($payload['shipment_id'] ?? null) ? $payload['shipment_id'] : $message->aggregateId();
        if ($shipmentId === '') {
            return;
        }

        $this->commandBus->dispatch(new AdvanceTracking(
            status: VisibleStatus::Rescheduled,
            shipmentId: $shipmentId,
        ));
    }
}
