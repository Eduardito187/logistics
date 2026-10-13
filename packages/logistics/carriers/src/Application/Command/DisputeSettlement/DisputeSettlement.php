<?php

declare(strict_types=1);

namespace Logistics\Carriers\Application\Command\DisputeSettlement;

use Logistics\Shared\Application\Command\Command;

/**
 * Intención de objetar una liquidación por una discrepancia, con el motivo.
 */
final readonly class DisputeSettlement implements Command
{
    public function __construct(
        public string $settlementId,
        public string $reason,
    ) {}
}
