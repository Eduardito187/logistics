<?php

declare(strict_types=1);

namespace Logistics\Inventory\Application\Command\ReserveStock;

use Logistics\Shared\Application\Command\Command;

/**
 * Intención de reservar stock de un lote (pasa de disponible a reservado).
 */
final readonly class ReserveStock implements Command
{
    public function __construct(
        public string $lotId,
        public int $quantity,
        public ?string $reference = null,
    ) {}
}
