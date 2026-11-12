<?php

declare(strict_types=1);

namespace Logistics\Shipments\Application\Query\CountShipmentsByStatus;

use Logistics\Shared\Application\Query\Query;

/**
 * Cantidad de envíos por estado para los MISMOS filtros del listado salvo el de estado (alimenta las
 * pestañas del listado: `meta.count_by_status` de GET /app/shipments, aditivo y a pedido).
 */
final readonly class CountShipmentsByStatus implements Query
{
    public function __construct(
        public ?string $source = null,
        public ?string $destinationNodeId = null,
        public ?string $deliveryDateFrom = null,
        public ?string $deliveryDateTo = null,
        public ?string $deliveryMode = null,
    ) {}
}
