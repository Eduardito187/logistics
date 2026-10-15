<?php

declare(strict_types=1);

namespace Logistics\Shipments\Application\Command\SetDeliverySlot;

use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;
use Logistics\Shipments\Domain\Contract\ShipmentRepository;
use Logistics\Shipments\Domain\Exception\ShipmentsException;
use Logistics\Shipments\Domain\ValueObject\DeliverySlot;
use Logistics\Shipments\Domain\ValueObject\ShipmentId;

/**
 * Fija la franja de entrega de un envío: carga el agregado, le asigna el slot (que rechaza si el envío
 * ya salió de recepción y es idempotente si es la misma franja), persiste y publica sus eventos al
 * outbox.
 *
 * El save del repositorio y el append al outbox corren dentro de la misma transacción, que abre el
 * decorador transaccional del CommandBus (Infrastructure); por eso acá no hay nada del framework.
 *
 * @implements CommandHandler<SetDeliverySlot>
 */
final readonly class SetDeliverySlotHandler implements CommandHandler
{
    public function __construct(
        private ShipmentRepository $shipments,
        private RecordDomainEvents $recordEvents,
    ) {}

    public function handle(Command $command): void
    {
        assert($command instanceof SetDeliverySlot);

        $shipment = $this->shipments->findById(ShipmentId::fromString($command->shipmentId))
            ?? throw ShipmentsException::because("no existe el envío {$command->shipmentId}");

        $shipment->assignDeliverySlot(DeliverySlot::of(
            $command->deliveryDate,
            $command->windowStart,
            $command->windowEnd,
        ));

        $this->shipments->save($shipment);

        $this->recordEvents->record($shipment->releaseEvents(), 'shipments.shipment');
    }
}
