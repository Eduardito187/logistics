<?php

declare(strict_types=1);

namespace Logistics\Shipments\Application\Query\FindShipment;

use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;
use Logistics\Shipments\Application\Contract\DeliveryModeReader;
use Logistics\Shipments\Application\Contract\ShipmentScopeReader;
use Logistics\Shipments\Application\Query\ShipmentListSupport;
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
        private readonly DeliveryModeReader $deliveryModes,
        private readonly ShipmentScopeReader $scope,
    ) {}

    public function handle(Query $query): ?ShipmentView
    {
        if (!$query instanceof FindShipment) {
            throw ShipmentsException::because('FindShipmentHandler solo maneja FindShipment');
        }

        $shipment = $this->shipments->findById(ShipmentId::fromString($query->shipmentId));

        if ($shipment === null) {
            return null;
        }

        // Modalidad de entrega derivada del tipo del nodo destino (aditivo, pedido #22 de la app) y, a pedido,
        // nodo de origen legible con los departamentos de origen y destino (aditivo, alcance por ciudad).
        $view = ShipmentView::fromShipment($shipment);
        $enriched = ShipmentListSupport::withDeliveryMode([$view], $this->deliveryModes);
        if ($query->withOrigin) {
            $enriched = ShipmentListSupport::withOrigin($enriched, $this->scope);
        }
        foreach ($enriched as $item) {
            return $item;
        }

        return $view;
    }
}
