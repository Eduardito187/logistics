<?php

declare(strict_types=1);

namespace Logistics\Shipments\Application\Command\ReceiveShipment;

use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;
use Logistics\Shipments\Domain\Contract\AddressConfidenceScorer;
use Logistics\Shipments\Domain\Contract\ShipmentRepository;
use Logistics\Shipments\Domain\Exception\ShipmentsException;
use Logistics\Shipments\Domain\Model\Shipment;
use Logistics\Shipments\Domain\Model\ShipmentLine;
use Logistics\Shipments\Domain\ValueObject\Address;
use Logistics\Shipments\Domain\ValueObject\GeoPoint;
use Logistics\Shipments\Domain\ValueObject\OrderSource;
use Logistics\Shipments\Domain\ValueObject\ProductName;
use Logistics\Shipments\Domain\ValueObject\ShipmentId;
use Logistics\Shipments\Domain\ValueObject\ShipmentLineId;
use Logistics\Shipments\Domain\ValueObject\Sku;
use Logistics\Shipments\Domain\ValueObject\SourceReference;

/**
 * Único punto de ingreso de pedidos. Normaliza el DTO al modelo propio, puntúa la dirección, persiste
 * y libera los eventos de dominio.
 *
 * Idempotente: si ya existe un envío para la misma referencia de origen, no duplica; deja disponible
 * el id del existente en lastShipmentId() y no vuelve a grabar eventos.
 *
 * @implements CommandHandler<ReceiveShipment>
 */
final class ReceiveShipmentHandler implements CommandHandler
{
    private ?ShipmentId $lastShipmentId = null;

    public function __construct(
        private readonly ShipmentRepository $shipments,
        private readonly AddressConfidenceScorer $scorer,
    ) {}

    public function handle(Command $command): void
    {
        if (!$command instanceof ReceiveShipment) {
            throw ShipmentsException::because('ReceiveShipmentHandler solo maneja ReceiveShipment');
        }

        $source = OrderSource::from($command->source);
        $sourceReference = SourceReference::of($source, $command->externalId);

        // Idempotencia: el mismo pedido de la misma fuente entra una sola vez (TODO integrar el outbox
        // al conectar los contextos; por ahora no se depende del package outbox).
        $existing = $this->shipments->findBySourceReference($sourceReference);
        if ($existing !== null) {
            $this->lastShipmentId = $existing->id();

            return;
        }

        $address = Address::of(
            $command->addressText,
            $command->addressReference,
            $this->geoPointFrom($command->lat, $command->lng),
        );

        $lines = [];
        foreach ($command->lines as $line) {
            $lines[] = ShipmentLine::create(
                ShipmentLineId::generate(),
                Sku::of($line->sku),
                ProductName::of($line->productName),
                $line->quantity,
            );
        }

        $confidence = $this->scorer->score($address);

        $shipment = Shipment::receive(
            $this->shipments->nextIdentity(),
            $source,
            $sourceReference,
            $command->customerName,
            $address,
            $confidence,
            $lines,
        );

        $this->shipments->save($shipment);
        $this->lastShipmentId = $shipment->id();

        // TODO(outbox): despachar estos eventos al outbox dentro de la misma transacción al integrar.
        $shipment->releaseEvents();
    }

    /**
     * Id del envío recibido (o del ya existente por idempotencia) tras el último handle().
     */
    public function lastShipmentId(): ShipmentId
    {
        if ($this->lastShipmentId === null) {
            throw ShipmentsException::because('todavía no se recibió ningún envío en este handler');
        }

        return $this->lastShipmentId;
    }

    private function geoPointFrom(?float $lat, ?float $lng): ?GeoPoint
    {
        if ($lat === null || $lng === null) {
            return null;
        }

        return GeoPoint::of($lat, $lng);
    }
}
