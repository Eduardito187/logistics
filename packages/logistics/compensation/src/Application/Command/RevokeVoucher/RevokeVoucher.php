<?php

declare(strict_types=1);

namespace Logistics\Compensation\Application\Command\RevokeVoucher;

use Logistics\Shared\Application\Command\Command;

/**
 * Anula (revoca) un vale antes de su canje. Propaga la desactivación a Magento por outbox.
 */
final readonly class RevokeVoucher implements Command
{
    public function __construct(
        public string $voucherId,
        public string $actor,
        public string $reason,
    ) {}
}
