<?php

declare(strict_types=1);

namespace Logistics\Shipments\Application\Command\RescheduleShipmentDelivery;

use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;
use Logistics\Shipments\Domain\Contract\ShipmentRepository;
use Logistics\Shipments\Domain\Exception\ShipmentsException;
use Logistics\Shipments\Domain\ValueObject\DeliverySlot;
use Logistics\Shipments\Domain\ValueObject\ShipmentId;

/**
 * Reprograma la entrega de un envío: carga el agregado, le fija la nueva franja (rechaza si está
 * entregado/fallido/cancelado; idempotente si es la misma), persiste y publica ShipmentRescheduled al
 * outbox (de ahí sale el avance del seguimiento y el aviso al cliente).
 *
 * @implements CommandHandler<RescheduleShipmentDelivery>
 */
final readonly class RescheduleShipmentDeliveryHandler implements CommandHandler
{
    public function __construct(
        private ShipmentRepository $shipments,
        private RecordDomainEvents $recordEvents,
    ) {}

    public function handle(Command $command): void
    {
        assert($command instanceof RescheduleShipmentDelivery);

        $shipment = $this->shipments->findById(ShipmentId::fromString($command->shipmentId))
            ?? throw ShipmentsException::because("no existe el envío {$command->shipmentId}");

        $shipment->reschedule(
            DeliverySlot::of($command->deliveryDate, $command->windowStart, $command->windowEnd),
            $command->reason,
        );

        $this->shipments->save($shipment);

        $this->recordEvents->record($shipment->releaseEvents(), 'shipments.shipment');
    }
}
