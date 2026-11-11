<?php

declare(strict_types=1);

namespace Logistics\Labels\Application\Command\RemovePrinter;

use Logistics\Shared\Application\Command\Command;

/**
 * Borra una impresora del registro (para retirarla sin borrar, usar UpdatePrinter con active=false).
 */
final readonly class RemovePrinter implements Command
{
    public function __construct(
        public string $printerId,
    ) {}
}
