<?php

declare(strict_types=1);

namespace Logistics\Compensation\Application\Command\ReleaseVoucherHold;

use Logistics\Shared\Application\Command\Command;

/**
 * Libera la reserva (hold) de un vale sin canjearlo.
 */
final readonly class ReleaseVoucherHold implements Command
{
    public function __construct(
        public string $code,
    ) {}
}
