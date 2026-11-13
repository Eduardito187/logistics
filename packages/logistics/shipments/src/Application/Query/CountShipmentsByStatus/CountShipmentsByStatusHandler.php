<?php

declare(strict_types=1);

namespace Logistics\Shipments\Application\Query\CountShipmentsByStatus;

use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;
use Logistics\Shipments\Application\Contract\ShipmentSummaryReader;
use Logistics\Shipments\Application\Query\ShipmentListSupport;
use Logistics\Shipments\Domain\Exception\ShipmentsException;
use Logistics\Shipments\Domain\ValueObject\OrderSource;

/**
 * Conteo por estado (GROUP BY status sobre la columna indexada), delegado en el lector agregado.
 *
 * @implements QueryHandler<CountShipmentsByStatus, array<string, int>>
 */
final class CountShipmentsByStatusHandler implements QueryHandler
{
    public function __construct(
        private readonly ShipmentSummaryReader $reader,
    ) {}

    /**
     * @return array<string, int>
     */
    public function handle(Query $query): array
    {
        if (!$query instanceof CountShipmentsByStatus) {
            throw ShipmentsException::because('CountShipmentsByStatusHandler solo maneja CountShipmentsByStatus');
        }

        return $this->reader->countByStatus(
            source: $query->source !== null ? OrderSource::fromLoose($query->source)->value : null,
            destinationNodeId: $query->destinationNodeId,
            deliveryDateFrom: $query->deliveryDateFrom,
            deliveryDateTo: $query->deliveryDateTo,
            deliveryMode: ShipmentListSupport::deliveryMode($query->deliveryMode),
        );
    }
}
