<?php

declare(strict_types=1);

namespace Logistics\Returns\Application\Command\InitiateReturn;

use Logistics\Shared\Application\Command\Command;

/**
 * Intención de iniciar una devolución (logística inversa).
 *
 * `ownerPartyId` null = retail (producto de Dismac); con valor = seller dueño. `kind` es el valor del enum
 * ReturnKind (`customer_return` | `return_to_vendor`).
 */
final readonly class InitiateReturn implements Command
{
    /**
     * @param list<array{sku: string, quantity: int, reason: string}> $lines
     */
    public function __construct(
        public string $originalReference,
        public string $warehouseId,
        public string $kind,
        public array $lines,
        public ?string $ownerPartyId = null,
    ) {}
}
