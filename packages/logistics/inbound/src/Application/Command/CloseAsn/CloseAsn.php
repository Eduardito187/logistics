<?php

declare(strict_types=1);

namespace Logistics\Inbound\Application\Command\CloseAsn;

use Logistics\Shared\Application\Command\Command;

/**
 * Intención de cerrar un ASN recibido y etiquetado.
 */
final readonly class CloseAsn implements Command
{
    public function __construct(
        public string $asnId,
    ) {}
}
