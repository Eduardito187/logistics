<?php

declare(strict_types=1);

namespace Logistics\Shipments\Application\Query\FindShipment;

use Logistics\Shared\Application\Query\Query;

/**
 * Busca un envío por su id. Devuelve un ShipmentView o null si no existe.
 */
final readonly class FindShipment implements Query
{
    public function __construct(
        public string $shipmentId,
    ) {}
}
