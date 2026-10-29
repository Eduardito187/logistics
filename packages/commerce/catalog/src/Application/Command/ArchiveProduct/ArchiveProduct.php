<?php

declare(strict_types=1);

namespace Commerce\Catalog\Application\Command\ArchiveProduct;

use Logistics\Shared\Application\Command\Command;

/**
 * Intención de archivar un producto (retirarlo de la venta, conservando su histórico).
 */
final readonly class ArchiveProduct implements Command
{
    public function __construct(
        public string $productId,
    ) {}
}
