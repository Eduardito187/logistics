<?php

declare(strict_types=1);

namespace Logistics\Returns\Application\Command\ReceiveReturn;

use Logistics\Shared\Application\Command\Command;

/**
 * Intención de registrar la recepción física de una devolución en el almacén central.
 */
final readonly class ReceiveReturn implements Command
{
    public function __construct(
        public string $returnId,
    ) {}
}
