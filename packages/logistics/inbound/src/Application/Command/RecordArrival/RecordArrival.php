<?php

declare(strict_types=1);

namespace Logistics\Inbound\Application\Command\RecordArrival;

use Logistics\Shared\Application\Command\Command;

/**
 * Intención de registrar la llegada del camión del seller para la cita del ASN.
 */
final readonly class RecordArrival implements Command
{
    public function __construct(
        public string $asnId,
        public ?string $carrierReference = null,
    ) {}
}
