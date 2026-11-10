<?php

declare(strict_types=1);

namespace Logistics\Shipments\Application\Query\ListShipmentsByCursor;

use Logistics\Shared\Application\Query\Query;

/**
 * Lista envíos con paginación por CURSOR (keyset), el modo preferido para la colección de envíos (crece
 * sin techo). Mismos filtros opcionales que ListShipments (estado, fuente, nodo destino), más el tamaño
 * de página y el cursor (`afterId` = id del último envío de la página previa; null = primera página).
 *
 * El nodo destino lo FUERZA la puerta app para el rol Tienda (server-side), igual que ListShipments.
 */
final readonly class ListShipmentsByCursor implements Query
{
    public function __construct(
        public int $limit,
        public ?string $afterId = null,
        public ?string $status = null,
        public ?string $source = null,
        public ?string $destinationNodeId = null,
    ) {}
}
