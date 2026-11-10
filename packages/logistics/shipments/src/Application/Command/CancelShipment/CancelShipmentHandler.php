<?php

declare(strict_types=1);

namespace Logistics\Shipments\Application\Command\CancelShipment;

use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;
use Logistics\Shipments\Domain\Contract\ShipmentRepository;
use Logistics\Shipments\Domain\Exception\ShipmentsException;
use Logistics\Shipments\Domain\ValueObject\ShipmentId;
use Logistics\Shipments\Domain\ValueObject\ShipmentStatus;

/**
 * Anula un envío: carga el agregado, aplica la transición (el dominio la valida), persiste y publica sus
 * eventos al outbox. El save y el append corren en la misma transacción que abre el decorador
 * transaccional del CommandBus (Infrastructure); por eso acá no hay nada del framework.
 *
 * Guarda por estado (no-op seguro bajo la reentrega at-least-once del outbox): un envío ya terminal
 * (Delivered / Failed / Cancelled) no se cancela. Para Delivered/Failed la decisión correcta es una
 * devolución, que gobierna el reactor, no este comando; acá solo se evita tocar el agregado y romper el
 * relay. El cupo/slot reservado NO se libera desde aquí: la reserva la tiene el contexto que la hizo
 * (promise/capacity, titular = order_id) y la libera su propio reactor por fanout del mismo
 * `orders.order_cancelled` (ADR 020 §4).
 *
 * @implements CommandHandler<CancelShipment>
 */
final readonly class CancelShipmentHandler implements CommandHandler
{
    public function __construct(
        private ShipmentRepository $shipments,
        private RecordDomainEvents $recordEvents,
    ) {}

    public function handle(Command $command): void
    {
        assert($command instanceof CancelShipment);

        $shipment = $this->shipments->findById(ShipmentId::fromString($command->shipmentId))
            ?? throw ShipmentsException::because("no existe el envío {$command->shipmentId}");

        if (in_array($shipment->status(), [
            ShipmentStatus::Delivered,
            ShipmentStatus::Failed,
            ShipmentStatus::Cancelled,
        ], true)) {
            return;
        }

        $shipment->cancel($command->reason);

        $this->shipments->save($shipment);

        $this->recordEvents->record($shipment->releaseEvents(), 'shipments.shipment');
    }
}
