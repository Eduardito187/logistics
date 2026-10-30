<?php

declare(strict_types=1);

namespace Commerce\Cart\Domain\Contract;

use Commerce\Cart\Domain\ValueObject\Sku;

/**
 * Puerto de consulta de disponibilidad (ATP) de un SKU. Es la frontera anti-corrupción hacia
 * `inventory` (ADR 018): el carrito pregunta por disponibilidad SIN escribir stock ni conocer el
 * Domain de inventory. La implementación real (adaptador) vive en Infrastructure.
 */
interface InventoryAvailabilityPort
{
    public function isAvailable(Sku $sku, int $qty): bool;
}
