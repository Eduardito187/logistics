<?php

declare(strict_types=1);

namespace Commerce\Pricing\Application\Command\ArchivePriceList;

use Logistics\Shared\Application\Command\Command;

/**
 * Intención de archivar (retirar) una lista de precios.
 */
final readonly class ArchivePriceList implements Command
{
    public function __construct(
        public string $priceListId,
    ) {}
}
