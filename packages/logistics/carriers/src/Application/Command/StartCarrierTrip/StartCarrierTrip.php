<?php

declare(strict_types=1);

namespace Logistics\Carriers\Application\Command\StartCarrierTrip;

use Logistics\Shared\Application\Command\Command;

/**
 * Intención del transportista (u oficina) de iniciar un viaje: recogió la carga en el origen.
 * Idempotente: repetirlo sobre un viaje ya en ruta no cambia nada.
 */
final readonly class StartCarrierTrip implements Command
{
    public function __construct(
        public string $tripId,
        public ?string $actorId = null,
    ) {}
}
