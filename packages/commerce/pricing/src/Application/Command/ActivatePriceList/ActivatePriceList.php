<?php

declare(strict_types=1);

namespace Commerce\Pricing\Application\Command\ActivatePriceList;

use Logistics\Shared\Application\Command\Command;

/**
 * Intención de activar una lista de precios para que quede vigente en su canal.
 */
final readonly class ActivatePriceList implements Command
{
    public function __construct(
        public string $priceListId,
    ) {}
}
