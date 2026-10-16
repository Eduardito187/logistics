<?php

declare(strict_types=1);

namespace Logistics\Shipments\Application\Query\ListShipments;

use Logistics\Shared\Application\Query\Query;

/**
 * Lista envíos, opcionalmente filtrados por estado y/o fuente (ambos como el valor del enum, o null) y
 * por nodo destino (UUID de un nodo de red, o null para no filtrar).
 *
 * El filtro por nodo destino lo FUERZA la puerta app para el rol Tienda (server-side): acota el listado
 * a los envíos de su propia tienda. Ver ListShipmentsController.
 */
final readonly class ListShipments implements Query
{
    public function __construct(
        public ?string $status = null,
        public ?string $source = null,
        public ?string $destinationNodeId = null,
    ) {}
}
