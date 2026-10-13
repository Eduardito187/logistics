<?php

declare(strict_types=1);

namespace Logistics\Shipments\Application\Query\ListLowConfidenceAddresses;

use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;
use Logistics\Shipments\Application\ReadModel\ShipmentView;
use Logistics\Shipments\Domain\Contract\ShipmentRepository;
use Logistics\Shipments\Domain\Exception\ShipmentsException;

/**
 * @implements QueryHandler<ListLowConfidenceAddresses, list<ShipmentView>>
 */
final class ListLowConfidenceAddressesHandler implements QueryHandler
{
    public function __construct(
        private readonly ShipmentRepository $shipments,
    ) {}

    /**
     * @return list<ShipmentView>
     */
    public function handle(Query $query): array
    {
        if (!$query instanceof ListLowConfidenceAddresses) {
            throw ShipmentsException::because('ListLowConfidenceAddressesHandler solo maneja ListLowConfidenceAddresses');
        }

        $views = [];
        foreach ($this->shipments->all() as $shipment) {
            if ($shipment->addressConfidence()->isLow()) {
                $views[] = ShipmentView::fromShipment($shipment);
            }
        }

        return $views;
    }
}
