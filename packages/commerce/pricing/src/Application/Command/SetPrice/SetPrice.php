<?php

declare(strict_types=1);

namespace Commerce\Pricing\Application\Command\SetPrice;

use Commerce\Pricing\Domain\ValueObject\Money;
use Logistics\Shared\Application\Command\Command;

/**
 * Intención de fijar (alta o actualización) el precio al público —IVA incluido— de un SKU en una lista.
 *
 * El monto va en centavos (entero), nunca float.
 */
final readonly class SetPrice implements Command
{
    public function __construct(
        public string $priceListId,
        public string $sku,
        public int $grossAmountCents,
        public string $currency = Money::DEFAULT_CURRENCY,
    ) {}
}
