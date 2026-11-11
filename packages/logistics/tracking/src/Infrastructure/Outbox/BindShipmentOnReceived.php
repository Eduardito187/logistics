<?php

declare(strict_types=1);

namespace Logistics\Tracking\Infrastructure\Outbox;

use Logistics\Outbox\Application\Contract\MessageDispatcher;
use Logistics\Outbox\Domain\OutboxMessage;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Tracking\Application\Command\BindShipmentToTracking\BindShipmentToTracking;

/**
 * Reactor outbox: cuando el pago crea el envío (`shipments.shipment_received`) liga ese envío al
 * seguimiento de su pedido. El `external_id` del envío lleva el id de la orden (orderId, o orderId#n:nodeId
 * en retiro): el `orderReference` del seguimiento es la parte anterior a «#n:». Reconcilia multishipping
 * (hermanos) en el handler. Tolerante e idempotente por shipmentId.
 */
final readonly class BindShipmentOnReceived implements MessageDispatcher
{
    public function __construct(
        private CommandBus $commandBus,
    ) {}

    public function dispatch(OutboxMessage $message): void
    {
        if ($message->eventName() !== 'shipments.shipment_received') {
            return;
        }

        $payload = $message->payload();
        $shipmentId = is_string($payload['shipment_id'] ?? null) ? $payload['shipment_id'] : $message->aggregateId();
        $externalId = is_string($payload['external_id'] ?? null) ? $payload['external_id'] : '';
        if ($externalId === '') {
            return;
        }

        $this->commandBus->dispatch(new BindShipmentToTracking(
            orderReference: explode('#n:', $externalId)[0],
            shipmentId: $shipmentId,
        ));
    }
}
