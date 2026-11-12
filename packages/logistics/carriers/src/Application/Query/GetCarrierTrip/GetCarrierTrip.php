<?php

declare(strict_types=1);

namespace Logistics\Carriers\Application\Query\GetCarrierTrip;

use Logistics\Shared\Application\Query\Query;

/**
 * Estado de un viaje de transportista por id (respuesta de iniciar/cerrar y control de dueño).
 */
final readonly class GetCarrierTrip implements Query
{
    public function __construct(
        public string $tripId,
    ) {}
}
