<?php

declare(strict_types=1);

namespace Logistics\Inventory\Application\Command\ReceiveStock;

use Logistics\Shared\Application\Command\Command;

/**
 * Intención de dar de alta un lote de stock al recibir mercadería.
 *
 * `fulfillmentMode` es el valor del enum FulfillmentMode (`owned` | `fbd` | `dropship`). Para stock propio
 * va `owned` y `ownerPartyId` null; para consignación de un seller va `fbd`/`dropship` con el id del seller.
 * `receivedAt` en ISO-8601; null = ahora.
 */
final readonly class ReceiveStock implements Command
{
    public function __construct(
        public string $warehouseId,
        public string $sku,
        public int $quantity,
        public string $fulfillmentMode = 'owned',
        public ?string $ownerPartyId = null,
        public ?string $sellerSkuRef = null,
        public int $unitCostCents = 0,
        public string $currency = 'BOB',
        public ?string $receivedAt = null,
        public ?string $goodsReceiptRef = null,
    ) {}
}
