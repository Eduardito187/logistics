<?php

declare(strict_types=1);

namespace Logistics\Sellers\Application\Command\ActivateSeller;

use Logistics\Shared\Application\Command\Command;

/**
 * Intención de activar a un seller para que quede habilitado a operar.
 */
final readonly class ActivateSeller implements Command
{
    public function __construct(
        public string $sellerId,
    ) {}
}
