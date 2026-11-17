<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Infrastructure\Outbox;

use Logistics\Outbox\Application\Contract\MessageDispatcher;
use Logistics\Outbox\Domain\OutboxMessage;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Telemetry\Application\Command\RefreshVehicleEtas\RefreshVehicleEtas;
use Throwable;

/**
 * Reactor outbox: al empezar un desvío (`telemetry.vehicle_deviation_started`), recalcula YA la ETA y la
 * geometría del reruteo por calles del vehículo (RefreshVehicleEtas, que consulta OSRM y cachea la polilínea).
 * Corre ASÍNCRONO en el worker del outbox, nunca en la ingesta (ADR 002: nada de OSRM dentro del request).
 *
 * Así, apenas el camión se sale de la ruta, la línea del mapa pasa de la recta de respaldo a la ruta por
 * calles sin esperar el barrido del minuto del scheduler. Idempotente y tolerante: RefreshVehicleEtas se puede
 * reejecutar sin efecto adverso; cualquier fallo se reporta y no rompe el procesamiento del outbox.
 */
final readonly class RefreshRerouteOnDeviation implements MessageDispatcher
{
    public function __construct(
        private CommandBus $commandBus,
    ) {}

    public function dispatch(OutboxMessage $message): void
    {
        if ($message->eventName() !== 'telemetry.vehicle_deviation_started') {
            return;
        }

        $vehicleId = $message->payload()['vehicle_id'] ?? null;
        if (!is_string($vehicleId) || $vehicleId === '') {
            return;
        }

        try {
            $this->commandBus->dispatch(new RefreshVehicleEtas($vehicleId));
        } catch (Throwable $e) {
            // El recálculo del reruteo es best-effort: el scheduler del minuto lo recalcula igual.
            report($e);
        }
    }
}
