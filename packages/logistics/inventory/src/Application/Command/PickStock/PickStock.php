<?php

declare(strict_types=1);

namespace Logistics\Inventory\Application\Command\PickStock;

use Logistics\Shared\Application\Command\Command;

/**
 * Intención de hacer pick de stock reservado de un lote (la mercadería sale del almacén; baja el on-hand).
 */
final readonly class PickStock implements Command
{
    public function __construct(
        public string $lotId,
        public int $quantity,
        public ?string $reference = null,
    ) {}
}
