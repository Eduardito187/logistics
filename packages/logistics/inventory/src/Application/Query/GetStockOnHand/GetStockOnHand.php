<?php

declare(strict_types=1);

namespace Logistics\Inventory\Application\Query\GetStockOnHand;

use Logistics\Shared\Application\Query\Query;

/**
 * Pide el stock on-hand de un almacén/sku, agregado por dueño. Filtros opcionales por dueño/modo.
 */
final readonly class GetStockOnHand implements Query
{
    public function __construct(
        public string $warehouseId,
        public string $sku,
        public ?string $ownerPartyId = null,
        public ?string $fulfillmentMode = null,
    ) {}
}
