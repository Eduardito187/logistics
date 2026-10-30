<?php

declare(strict_types=1);

namespace Commerce\Promotions\Application\Command\ExpirePromotion;

use Logistics\Shared\Application\Command\Command;

/**
 * Intención de expirar una promoción (que deje de aplicar).
 */
final readonly class ExpirePromotion implements Command
{
    public function __construct(
        public string $promotionId,
    ) {}
}
