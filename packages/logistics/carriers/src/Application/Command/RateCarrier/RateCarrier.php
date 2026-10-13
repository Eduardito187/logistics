<?php

declare(strict_types=1);

namespace Logistics\Carriers\Application\Command\RateCarrier;

use Logistics\Shared\Application\Command\Command;

/**
 * Intención de calificar a un transportista (escala 1 a 5).
 */
final readonly class RateCarrier implements Command
{
    public function __construct(
        public string $carrierId,
        public int $rating,
    ) {}
}
