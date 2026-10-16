<?php

declare(strict_types=1);

namespace Logistics\Returns\Application\Command\DispositionReturn;

use Logistics\Shared\Application\Command\Command;

/**
 * Intención de decidir la disposición de una devolución recibida.
 *
 * `disposition` es el valor del enum Disposition (`restock` | `return_to_seller` | `scrap`).
 */
final readonly class DispositionReturn implements Command
{
    public function __construct(
        public string $returnId,
        public string $disposition,
    ) {}
}
