<?php

declare(strict_types=1);

namespace Logistics\Compensation\Application\Command\HoldVoucher;

use Logistics\Shared\Application\Command\Command;

/**
 * Reserva temporal (hold) de un vale para una venta del POS, por un ttl en minutos.
 */
final readonly class HoldVoucher implements Command
{
    public function __construct(
        public string $code,
        public string $channel,
        public int $ttlMinutes,
    ) {}
}
