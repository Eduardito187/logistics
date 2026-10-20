<?php

declare(strict_types=1);

namespace Logistics\Shipments\Application\Query\ListLowConfidenceAddresses;

use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;
use Logistics\Shipments\Application\Contract\ShipmentListReader;
use Logistics\Shipments\Application\ReadModel\ShipmentView;
use Logistics\Shipments\Domain\Exception\ShipmentsException;

/**
 * Lista los envíos con dirección de confianza baja (lado de lectura CQRS).
 *
 * Delega en ShipmentListReader con el filtro `lowConfidenceOnly`, que lo empuja a SQL (columna
 * `address_confidence_level`) en vez de recorrer todos los agregados en memoria.
 *
 * @implements QueryHandler<ListLowConfidenceAddresses, list<ShipmentView>>
 */
final class ListLowConfidenceAddressesHandler implements QueryHandler
{
    public function __construct(
        private readonly ShipmentListReader $reader,
    ) {}

    /**
     * @return list<ShipmentView>
     */
    public function handle(Query $query): array
    {
        if (!$query instanceof ListLowConfidenceAddresses) {
            throw ShipmentsException::because('ListLowConfidenceAddressesHandler solo maneja ListLowConfidenceAddresses');
        }

        return $this->reader->list(lowConfidenceOnly: true);
    }
}
