<?php

declare(strict_types=1);

namespace Logistics\Settlement\Application\Command\ConfirmSettlement;

use Logistics\Shared\Application\Command\Command;

/**
 * Intención de confirmar una liquidación de seller en borrador (pasa a pendiente de pago).
 */
final readonly class ConfirmSettlement implements Command
{
    public function __construct(
        public string $settlementId,
    ) {}
}
