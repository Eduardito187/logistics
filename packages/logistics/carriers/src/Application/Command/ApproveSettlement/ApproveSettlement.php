<?php

declare(strict_types=1);

namespace Logistics\Carriers\Application\Command\ApproveSettlement;

use Logistics\Shared\Application\Command\Command;

/**
 * Intención de la operación de aprobar una liquidación enviada por un transportista.
 */
final readonly class ApproveSettlement implements Command
{
    public function __construct(
        public string $settlementId,
    ) {}
}
