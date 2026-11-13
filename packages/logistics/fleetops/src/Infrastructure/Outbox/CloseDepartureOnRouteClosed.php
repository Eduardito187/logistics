<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Infrastructure\Outbox;

use Logistics\FleetOps\Application\Command\CloseDeparture\CloseDeparture;
use Logistics\FleetOps\Domain\Contract\DepartureRepository;
use Logistics\Outbox\Application\Contract\MessageDispatcher;
use Logistics\Outbox\Domain\OutboxMessage;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Reactor outbox (Fase 3.3): cuando el chofer CIERRA la ruta del día (`execution.driver_route_closed`), la
 * FLOTA cierra la salida del vehículo (Departure → Returned) con el odómetro de regreso, cerrando el ciclo
 * salida→cierre del viaje de flota. Último eslabón de la cadena publicación → ruta → salida → cierre.
 *
 * El odómetro de regreso sale del evento; si no viene o fuese menor al de salida (el odómetro no retrocede),
 * se usa el de salida (km recorridos = 0) para no rechazar el cierre.
 *
 * Idempotente: solo cierra si hay una salida ABIERTA para el vehículo. Tras cerrarla, una reentrega del
 * mismo hecho no encuentra salida abierta y no hace nada.
 */
final readonly class CloseDepartureOnRouteClosed implements MessageDispatcher
{
    public function __construct(
        private CommandBus $commandBus,
        private DepartureRepository $departures,
    ) {}

    public function dispatch(OutboxMessage $message): void
    {
        if ($message->eventName() !== 'execution.driver_route_closed') {
            return;
        }

        $payload = $message->payload();

        $vehicleId = $payload['vehicle_id'] ?? null;
        $returnedAt = $payload['occurred_at'] ?? null;
        if (!is_string($vehicleId) || $vehicleId === '' || !is_string($returnedAt) || $returnedAt === '') {
            return;
        }

        $departure = $this->departures->openForVehicle($vehicleId);
        if ($departure === null) {
            // No hay salida abierta (ya cerrada, o nunca se abrió por este camino): nada que cerrar.
            return;
        }

        // Una salida abierta normalmente lleva su odómetro de salida (se registra al abrirla); el tipo lo
        // declara opcional, así que lo tratamos como tal.
        $odometerOut = $departure->odometerOut()?->km;
        $odometer = $payload['odometer_km'] ?? null;
        $odometerIn = is_numeric($odometer) ? (int) round((float) $odometer) : $odometerOut;

        if ($odometerIn === null) {
            // Sin odómetro de regreso en el evento ni de salida registrado no hay base para cerrar: se omite.
            return;
        }

        // El odómetro no retrocede: el de regreso nunca es menor al de salida (cuando este se conoce).
        if ($odometerOut !== null) {
            $odometerIn = max($odometerIn, $odometerOut);
        }

        $this->commandBus->dispatch(new CloseDeparture(
            departureId: $departure->id()->toString(),
            odometerInKm: $odometerIn,
            returnedAt: $returnedAt,
        ));
    }
}
