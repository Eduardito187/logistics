<?php

declare(strict_types=1);

namespace Logistics\Carriers\Presentation\Http\Controller;

use Logistics\Carriers\Application\Command\CloseCarrierTrip\CloseCarrierTrip;
use Logistics\Shared\Application\Command\Command;

/**
 * `POST /app/carriers/trips/{tripId}/close`: el transportista entregó la carga y cierra el viaje.
 * Idempotente: repetirlo devuelve el mismo `closed_at`.
 */
final class CloseCarrierTripController extends CarrierTripTransitionController
{
    protected function command(string $tripId, ?string $actorId): Command
    {
        return new CloseCarrierTrip(tripId: $tripId, actorId: $actorId);
    }
}
