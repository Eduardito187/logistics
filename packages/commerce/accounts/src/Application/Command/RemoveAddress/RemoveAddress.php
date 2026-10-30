<?php

declare(strict_types=1);

namespace Commerce\Accounts\Application\Command\RemoveAddress;

use Logistics\Shared\Application\Command\Command;

/**
 * Intención de quitar una dirección de una cuenta (identificada por su etiqueta).
 */
final readonly class RemoveAddress implements Command
{
    public function __construct(
        public string $accountId,
        public string $label,
    ) {}
}
