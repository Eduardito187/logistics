<?php

declare(strict_types=1);

namespace Logistics\Shipments\Application\Query\FindShipment;

use Logistics\Shared\Application\Query\Query;

/**
 * Busca un envío por su id. Devuelve un ShipmentView o null si no existe.
 *
 * `withOrigin` adjunta el nodo de origen legible y los departamentos de origen y destino (una lectura más):
 * lo pide la ficha del envío de la app; los demás consumidores no lo necesitan.
 */
final readonly class FindShipment implements Query
{
    public function __construct(
        public string $shipmentId,
        public bool $withOrigin = false,
    ) {}
}
