<?php

declare(strict_types=1);

namespace Logistics\Inventory\Application\Query\ListLots;

use Logistics\Shared\Application\Query\Query;

/**
 * Lista lotes con filtros opcionales por almacén / sku / dueño / modo, paginado.
 */
final readonly class ListLots implements Query
{
    public function __construct(
        public ?string $warehouseId = null,
        public ?string $sku = null,
        public ?string $ownerPartyId = null,
        public ?string $fulfillmentMode = null,
        public int $limit = 100,
        public int $offset = 0,
    ) {}
}
