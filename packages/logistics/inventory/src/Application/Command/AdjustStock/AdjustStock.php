<?php

declare(strict_types=1);

namespace Logistics\Inventory\Application\Command\AdjustStock;

use Logistics\Shared\Application\Command\Command;

/**
 * Intención de ajustar el stock disponible de un lote en `delta` (positivo o negativo), con un motivo.
 */
final readonly class AdjustStock implements Command
{
    public function __construct(
        public string $lotId,
        public int $delta,
        public string $reason,
    ) {}
}
