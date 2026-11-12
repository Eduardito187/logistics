<?php

declare(strict_types=1);

namespace Commerce\Catalog\Application\Query\GetProductBySku;

use Logistics\Shared\Application\Query\Query;

/**
 * Consulta un producto por su SKU canónico (puente con Magento/integraciones; no es un UUID).
 */
final readonly class GetProductBySku implements Query
{
    public function __construct(
        public string $sku,
    ) {}
}
