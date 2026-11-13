<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Application\Command\BindTripToRoute;

use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;
use Logistics\Telemetry\Domain\Contract\TripRepository;

/**
 * Liga el trip activo del vehículo a la ruta dada y lo persiste. Sin evento de dominio: el vínculo es un
 * dato de correlación (ver Trip::bindToRoute), no un hecho de negocio, así que no va al outbox.
 *
 * Tolerante e idempotente: si el vehículo no tiene trip activo (todavía no llegaron pings), no hace nada;
 * si el trip ya estaba ligado, no reescribe el vínculo. La reentrega at-least-once del hecho de origen no
 * duplica ni corrompe nada.
 *
 * @implements CommandHandler<BindTripToRoute>
 */
final readonly class BindTripToRouteHandler implements CommandHandler
{
    public function __construct(
        private TripRepository $trips,
    ) {}

    public function handle(Command $command): void
    {
        assert($command instanceof BindTripToRoute);

        $trip = $this->trips->activeForVehicle($command->vehicleId);
        if ($trip === null) {
            return;
        }

        if ($trip->bindToRoute($command->routeId)) {
            $this->trips->save($trip);
        }
    }
}
