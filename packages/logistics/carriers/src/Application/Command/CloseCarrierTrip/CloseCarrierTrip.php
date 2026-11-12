<?php

declare(strict_types=1);

namespace Logistics\Carriers\Application\Command\CloseCarrierTrip;

use Logistics\Shared\Application\Command\Command;

/**
 * Intención del transportista (u oficina) de cerrar un viaje: entregó la carga en el destino.
 * Idempotente: repetirlo sobre un viaje ya cerrado no cambia nada.
 */
final readonly class CloseCarrierTrip implements Command
{
    public function __construct(
        public string $tripId,
        public ?string $actorId = null,
    ) {}
}
