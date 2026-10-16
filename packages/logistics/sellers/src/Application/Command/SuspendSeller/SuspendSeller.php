<?php

declare(strict_types=1);

namespace Logistics\Sellers\Application\Command\SuspendSeller;

use Logistics\Shared\Application\Command\Command;

/**
 * Intención de suspender a un seller para que deje de operar hasta reactivarlo.
 */
final readonly class SuspendSeller implements Command
{
    public function __construct(
        public string $sellerId,
    ) {}
}
