<?php

declare(strict_types=1);

namespace Logistics\Compensation\Application\Command\RedeemVoucher;

use Logistics\Shared\Application\Command\Command;

/**
 * Canjea un vale (un solo uso). Idempotente por `saleRef`. Lo llama el POS vía API o dis-commerce por el bus.
 */
final readonly class RedeemVoucher implements Command
{
    public function __construct(
        public string $code,
        public string $channel,
        public string $saleRef,
        public int $amountUsedCents,
        public ?string $customerEmail = null,
    ) {}
}
