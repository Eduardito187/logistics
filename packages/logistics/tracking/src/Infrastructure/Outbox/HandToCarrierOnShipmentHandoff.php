<?php

declare(strict_types=1);

namespace Logistics\Tracking\Infrastructure\Outbox;

use Logistics\Outbox\Application\Contract\MessageDispatcher;
use Logistics\Outbox\Domain\OutboxMessage;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Tracking\Application\Command\AdvanceTracking\AdvanceTracking;
use Logistics\Tracking\Domain\ValueObject\VisibleStatus;

/**
 * Reactor outbox: cuando NUESTRO lado entrega la carga a la transportadora en su almacén
 * (`execution.shipment_handed_to_carrier`, el handoff que decide el dueño), el seguimiento del envío pasa a
 * «entregado a la transportadora» (HANDED_TO_CARRIER). Es el disparador PRIMARIO del estado (el handoff
 * propio); el de `carriers.carrier_trip_started` queda como respaldo (ambos idempotentes).
 *
 * Tolerante e idempotente: un envío sin seguimiento o cuyo estado no admite la transición se saltea (lo
 * decide AdvanceTracking); nunca lanza, para no trabar el relay por un envío fuera de flujo.
 */
final readonly class HandToCarrierOnShipmentHandoff implements MessageDispatcher
{
    public function __construct(
        private CommandBus $commandBus,
    ) {}

    public function dispatch(OutboxMessage $message): void
    {
        if ($message->eventName() !== 'execution.shipment_handed_to_carrier') {
            return;
        }

        $shipmentId = $message->payload()['shipment_id'] ?? null;
        if (!is_string($shipmentId) || trim($shipmentId) === '') {
            return;
        }

        $this->commandBus->dispatch(new AdvanceTracking(
            status: VisibleStatus::HandedToCarrier,
            shipmentId: trim($shipmentId),
        ));
    }
}
