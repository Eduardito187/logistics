<?php

declare(strict_types=1);

namespace Commerce\Pricing\Application\Query\GetPrice;

use Logistics\Shared\Application\Query\Query;

/**
 * Pide el presupuesto de precio de un SKU en un canal (resuelve la lista activa del canal + impuestos).
 */
final readonly class GetPrice implements Query
{
    public function __construct(
        public string $sku,
        public string $channel,
    ) {}
}
