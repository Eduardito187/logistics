<?php

declare(strict_types=1);

namespace Logistics\Tracking\Infrastructure\Outbox;

use Logistics\Outbox\Application\Contract\MessageDispatcher;
use Logistics\Outbox\Domain\OutboxMessage;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Tracking\Application\Command\AdvanceTracking\AdvanceTracking;
use Logistics\Tracking\Domain\ValueObject\VisibleStatus;

/**
 * Reactor outbox: al iniciar un viaje de transportadora (`carriers.carrier_trip_started`) cada envío de la
 * carga pasa a «entregado a la transportadora». Lee la lista `shipment_ids` del payload y avanza uno por
 * uno por `shipmentId`.
 *
 * Tolerante e idempotente: un envío sin seguimiento o cuyo estado no admite la transición se saltea (lo
 * decide AdvanceTracking); nunca lanza, para no trabar el relay por un envío fuera de flujo.
 */
final readonly class HandToCarrierOnCarrierTripStarted implements MessageDispatcher
{
    public function __construct(
        private CommandBus $commandBus,
    ) {}

    public function dispatch(OutboxMessage $message): void
    {
        if ($message->eventName() !== 'carriers.carrier_trip_started') {
            return;
        }

        $shipmentIds = $message->payload()['shipment_ids'] ?? null;
        if (!is_array($shipmentIds)) {
            return;
        }

        foreach ($shipmentIds as $shipmentId) {
            if (!is_string($shipmentId) || trim($shipmentId) === '') {
                continue;
            }

            $this->commandBus->dispatch(new AdvanceTracking(
                status: VisibleStatus::HandedToCarrier,
                shipmentId: trim($shipmentId),
            ));
        }
    }
}
