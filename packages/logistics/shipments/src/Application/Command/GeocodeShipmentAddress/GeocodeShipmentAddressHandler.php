<?php

declare(strict_types=1);

namespace Logistics\Shipments\Application\Command\GeocodeShipmentAddress;

use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;
use Logistics\Shipments\Domain\Contract\AddressConfidenceScorer;
use Logistics\Shipments\Domain\Contract\ShipmentRepository;
use Logistics\Shipments\Domain\Exception\ShipmentsException;
use Logistics\Shipments\Domain\ValueObject\ShipmentId;

/**
 * Recalcula la confianza de la dirección de un envío con el geocodificador y la persiste.
 *
 * @implements CommandHandler<GeocodeShipmentAddress>
 */
final class GeocodeShipmentAddressHandler implements CommandHandler
{
    public function __construct(
        private readonly ShipmentRepository $shipments,
        private readonly AddressConfidenceScorer $scorer,
        private readonly RecordDomainEvents $recordEvents,
    ) {}

    public function handle(Command $command): void
    {
        if (!$command instanceof GeocodeShipmentAddress) {
            throw ShipmentsException::because('GeocodeShipmentAddressHandler solo maneja GeocodeShipmentAddress');
        }

        $shipment = $this->shipments->findById(ShipmentId::fromString($command->shipmentId));
        if ($shipment === null) {
            throw ShipmentsException::because("no existe el envío {$command->shipmentId}");
        }

        $confidence = $this->scorer->score($shipment->address());
        $shipment->rescoreAddress($confidence);

        $this->shipments->save($shipment);

        $this->recordEvents->record($shipment->releaseEvents(), 'shipments.shipment');
    }
}
