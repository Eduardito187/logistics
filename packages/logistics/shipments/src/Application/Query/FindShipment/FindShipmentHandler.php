<?php

declare(strict_types=1);

namespace Logistics\Shipments\Application\Query\FindShipment;

use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;
use Logistics\Shipments\Application\ReadModel\ShipmentView;
use Logistics\Shipments\Domain\Contract\ShipmentRepository;
use Logistics\Shipments\Domain\Exception\ShipmentsException;
use Logistics\Shipments\Domain\ValueObject\ShipmentId;

/**
 * @implements QueryHandler<FindShipment, ?ShipmentView>
 */
final class FindShipmentHandler implements QueryHandler
{
    public function __construct(
        private readonly ShipmentRepository $shipments,
    ) {}

    public function handle(Query $query): ?ShipmentView
    {
        if (!$query instanceof FindShipment) {
            throw ShipmentsException::because('FindShipmentHandler solo maneja FindShipment');
        }

        $shipment = $this->shipments->findById(ShipmentId::fromString($query->shipmentId));

        return $shipment === null ? null : ShipmentView::fromShipment($shipment);
    }
}
