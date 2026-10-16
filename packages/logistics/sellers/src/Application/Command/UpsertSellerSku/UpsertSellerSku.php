<?php

declare(strict_types=1);

namespace Logistics\Sellers\Application\Command\UpsertSellerSku;

use Logistics\Shared\Application\Command\Command;

/**
 * Intención de dar de alta o actualizar un SKU del catálogo de un seller (y su mapeo opcional a SAP).
 */
final readonly class UpsertSellerSku implements Command
{
    public function __construct(
        public string $sellerId,
        public string $sku,
        public ?string $sapMaterial = null,
    ) {}
}
