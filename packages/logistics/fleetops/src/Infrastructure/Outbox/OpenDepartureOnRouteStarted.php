<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Infrastructure\Outbox;

use Logistics\FleetOps\Application\Command\RegisterDeparture\RegisterDeparture;
use Logistics\FleetOps\Domain\Contract\DepartureRepository;
use Logistics\Outbox\Application\Contract\MessageDispatcher;
use Logistics\Outbox\Domain\OutboxMessage;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Reactor outbox (Fase 3.3): cuando el chofer INICIA la ruta del día (`execution.driver_route_started`), la
 * FLOTA abre la salida/despacho del vehículo (Departure en estado Out) ligada a la ruta. Es el eslabón que
 * conecta "la ruta arrancó" con el registro operativo del viaje de flota propia, sin captura manual.
 *
 * La salida nace con lo que trae el evento: odómetro de salida y fecha; el `route_ref` apunta a la ruta de
 * ejecución para poder cerrarla al volver. Chofer, ayudantes y carga quedan vacíos (el evento de inicio no
 * los trae); se enriquecen aparte si hace falta.
 *
 * Idempotente: un vehículo tiene a lo sumo UNA salida abierta. Si ya hay una (reentrega del hecho, o salida
 * registrada a mano), no se abre otra. El outbox reentrega at-least-once.
 */
final readonly class OpenDepartureOnRouteStarted implements MessageDispatcher
{
    public function __construct(
        private CommandBus $commandBus,
        private DepartureRepository $departures,
    ) {}

    public function dispatch(OutboxMessage $message): void
    {
        if ($message->eventName() !== 'execution.driver_route_started') {
            return;
        }

        $payload = $message->payload();

        $vehicleId = $payload['vehicle_id'] ?? null;
        $departedAt = $payload['occurred_at'] ?? null;
        $routeId = $payload['route_id'] ?? null;
        if (!is_string($vehicleId) || $vehicleId === '' || !is_string($departedAt) || $departedAt === '') {
            return;
        }

        // Idempotencia: ya hay una salida en ruta para el vehículo → no abrir otra.
        if ($this->departures->openForVehicle($vehicleId) !== null) {
            return;
        }

        $odometer = $payload['odometer_km'] ?? null;

        $this->commandBus->dispatch(new RegisterDeparture(
            vehicleId: $vehicleId,
            driverId: null,
            helperIds: [],
            destination: null,
            routeRef: is_string($routeId) && $routeId !== '' ? $routeId : null,
            load: [],
            odometerOutKm: is_numeric($odometer) ? (int) round((float) $odometer) : null,
            departedAt: $departedAt,
            note: 'Apertura automática al iniciar la ruta del día.',
        ));
    }
}
