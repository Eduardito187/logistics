<?php

declare(strict_types=1);

namespace Logistics\Inbound\Application\Command\LabelReceivedUnits;

use Logistics\Shared\Application\Command\Command;

/**
 * Intención de etiquetar las unidades aceptadas de la recepción de un ASN (punto de convergencia).
 */
final readonly class LabelReceivedUnits implements Command
{
    public function __construct(
        public string $asnId,
    ) {}
}
