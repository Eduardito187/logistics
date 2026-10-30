<?php

declare(strict_types=1);

namespace Commerce\Promotions\Application\Command\ActivatePromotion;

use Logistics\Shared\Application\Command\Command;

/**
 * Intención de activar una promoción (que pase a aplicar si se cumplen sus condiciones).
 */
final readonly class ActivatePromotion implements Command
{
    public function __construct(
        public string $promotionId,
    ) {}
}
