<?php

declare(strict_types=1);

namespace Logistics\Inventory\Application\Query\GetAvailableToPromise;

use Logistics\Shared\Application\Query\Query;

/**
 * Pide el Available-to-Promise de un almacén/sku (stock disponible, no reservado), opcionalmente por dueño.
 */
final readonly class GetAvailableToPromise implements Query
{
    public function __construct(
        public string $warehouseId,
        public string $sku,
        public ?string $ownerPartyId = null,
    ) {}
}
