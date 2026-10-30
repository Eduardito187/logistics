<?php

declare(strict_types=1);

namespace Commerce\Pricing\Application\Command\CreatePriceList;

use Commerce\Pricing\Domain\ValueObject\Money;
use Logistics\Shared\Application\Command\Command;

/**
 * Intención de crear una lista de precios nueva para un canal.
 */
final readonly class CreatePriceList implements Command
{
    public function __construct(
        public string $name,
        public string $channel,
        public string $currency = Money::DEFAULT_CURRENCY,
    ) {}
}
