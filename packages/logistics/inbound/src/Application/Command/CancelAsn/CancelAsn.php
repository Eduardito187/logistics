<?php

declare(strict_types=1);

namespace Logistics\Inbound\Application\Command\CancelAsn;

use Logistics\Shared\Application\Command\Command;

/**
 * Intención de cancelar un ASN que no se va a recibir.
 */
final readonly class CancelAsn implements Command
{
    public function __construct(
        public string $asnId,
        public ?string $reason = null,
    ) {}
}
