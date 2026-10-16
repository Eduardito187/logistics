<?php

declare(strict_types=1);

namespace Logistics\Inventory\Application\Command\TransferStock;

use Logistics\Shared\Application\Command\Command;

/**
 * Intención de transferir stock disponible de un lote hacia otro almacén. La propiedad no cambia: el lote
 * destino conserva el mismo dueño (segregación lógica).
 */
final readonly class TransferStock implements Command
{
    public function __construct(
        public string $lotId,
        public int $quantity,
        public string $destinationWarehouseId,
        public ?string $reference = null,
    ) {}
}
