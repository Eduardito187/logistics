<?php

declare(strict_types=1);

namespace Logistics\Inventory\Application\Command\ReleaseStock;

use Logistics\Shared\Application\Command\Command;

/**
 * Intención de liberar una reserva de un lote (vuelve de reservado a disponible).
 */
final readonly class ReleaseStock implements Command
{
    public function __construct(
        public string $lotId,
        public int $quantity,
        public ?string $reference = null,
    ) {}
}
