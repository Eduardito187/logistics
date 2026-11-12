<?php

declare(strict_types=1);

namespace Logistics\Carriers\Presentation\Http\Controller;

use Logistics\Carriers\Application\Command\StartCarrierTrip\StartCarrierTrip;
use Logistics\Shared\Application\Command\Command;

/**
 * `POST /app/carriers/trips/{tripId}/start`: el transportista recogió la carga e inicia el viaje.
 * Idempotente: repetirlo devuelve el mismo `started_at`.
 */
final class StartCarrierTripController extends CarrierTripTransitionController
{
    protected function command(string $tripId, ?string $actorId): Command
    {
        return new StartCarrierTrip(tripId: $tripId, actorId: $actorId);
    }
}
