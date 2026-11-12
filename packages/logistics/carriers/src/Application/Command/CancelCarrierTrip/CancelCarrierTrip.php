<?php

declare(strict_types=1);

namespace Logistics\Carriers\Application\Command\CancelCarrierTrip;

use Logistics\Shared\Application\Command\Command;

/**
 * Intención de la oficina de cancelar un viaje programado (no salió). Libera sus envíos.
 */
final readonly class CancelCarrierTrip implements Command
{
    public function __construct(
        public string $tripId,
        public ?string $reason = null,
    ) {}
}
