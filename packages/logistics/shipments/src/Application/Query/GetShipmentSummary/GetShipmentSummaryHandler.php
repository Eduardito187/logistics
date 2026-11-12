<?php

declare(strict_types=1);

namespace Logistics\Shipments\Application\Query\GetShipmentSummary;

use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;
use Logistics\Shipments\Application\Contract\ShipmentSummaryReader;
use Logistics\Shipments\Application\Query\ShipmentListSupport;
use Logistics\Shipments\Application\ReadModel\ShipmentSummaryView;
use Logistics\Shipments\Domain\Exception\ShipmentsException;

/**
 * Resuelve el resumen del tablero delegando en el lector agregado (SQL GROUP BY, caché corta).
 *
 * @implements QueryHandler<GetShipmentSummary, ShipmentSummaryView>
 */
final class GetShipmentSummaryHandler implements QueryHandler
{
    public function __construct(
        private readonly ShipmentSummaryReader $reader,
    ) {}

    public function handle(Query $query): ShipmentSummaryView
    {
        if (!$query instanceof GetShipmentSummary) {
            throw ShipmentsException::because('GetShipmentSummaryHandler solo maneja GetShipmentSummary');
        }

        return $this->reader->summary(
            deliveryDateFrom: $query->deliveryDateFrom,
            deliveryDateTo: $query->deliveryDateTo,
            destinationNodeId: $query->destinationNodeId,
            deliveryMode: ShipmentListSupport::deliveryMode($query->deliveryMode),
            periodFrom: $query->periodFrom,
            periodTo: $query->periodTo,
        );
    }
}
