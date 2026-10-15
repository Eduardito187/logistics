<?php

declare(strict_types=1);

namespace Logistics\Shipments\Application\Command\GeocodeShipmentAddress;

use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;
use Logistics\Shipments\Domain\Contract\Geocoder;
use Logistics\Shipments\Domain\Contract\ShipmentRepository;
use Logistics\Shipments\Domain\Exception\ShipmentsException;
use Logistics\Shipments\Domain\ValueObject\ShipmentId;

/**
 * Re-geocodifica la dirección de un envío: recalcula su confianza y, si el proveedor devolvió una
 * coordenada, también fija el punto geográfico. Luego persiste y publica el hecho al outbox.
 *
 * @implements CommandHandler<GeocodeShipmentAddress>
 */
final class GeocodeShipmentAddressHandler implements CommandHandler
{
    public function __construct(
        private readonly ShipmentRepository $shipments,
        private readonly Geocoder $geocoder,
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

        $result = $this->geocoder->geocode($shipment->address());

        // Si el proveedor ubicó la dirección, se fija además la coordenada; si no, solo se recalcula la
        // confianza (p. ej. el stub o un ZERO_RESULTS), sin borrar un punto que ya tuviera el envío.
        $point = $result->geoPoint;
        $address = $point !== null
            ? $shipment->address()->withGeoPoint($point)
            : null;

        $shipment->rescoreAddress($result->confidence, $address);

        $this->shipments->save($shipment);

        $this->recordEvents->record($shipment->releaseEvents(), 'shipments.shipment');
    }
}
