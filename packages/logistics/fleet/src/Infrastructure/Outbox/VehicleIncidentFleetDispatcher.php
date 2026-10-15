<?php

declare(strict_types=1);

namespace Logistics\Fleet\Infrastructure\Outbox;

use Logistics\Fleet\Application\Command\ChangeVehicleStatus\ChangeVehicleStatus;
use Logistics\Fleet\Domain\ValueObject\VehicleStatus;
use Logistics\Outbox\Application\Contract\MessageDispatcher;
use Logistics\Outbox\Domain\OutboxMessage;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Reacción de FLOTA ante un incidente de camión (execution.vehicle_incident_reported) que llega por el
 * outbox: si el incidente es MAYOR (accidente/avería grave), el vehículo pasa a FUERA DE SERVICIO
 * (mantenimiento), de modo que la planificación no lo vuelva a asignar hasta que un responsable lo
 * reactive.
 *
 * Un incidente MENOR no cambia el estado del vehículo (la ruta continúa con retraso), así que se ignora.
 *
 * Idempotente: ChangeVehicleStatus es idempotente en el dominio (si el vehículo ya está en mantenimiento,
 * no hace nada ni graba evento), así que la reentrega at-least-once del outbox no causa efectos extra.
 * Encola el comando por el CommandBus, que abre su propia transacción con el outbox (como lo hace el
 * dispatcher de notificaciones); no hay I/O síncrono acá.
 */
final readonly class VehicleIncidentFleetDispatcher implements MessageDispatcher
{
    public function __construct(
        private CommandBus $commandBus,
    ) {}

    public function dispatch(OutboxMessage $message): void
    {
        if ($message->eventName() !== 'execution.vehicle_incident_reported') {
            return;
        }

        $payload = $message->payload();

        $severity = $payload['severity'] ?? null;
        if ($severity !== 'MAJOR') {
            return;
        }

        $vehicleId = $payload['vehicle_id'] ?? null;
        if (!is_string($vehicleId) || $vehicleId === '') {
            return;
        }

        $this->commandBus->dispatch(new ChangeVehicleStatus(
            vehicleId: $vehicleId,
            status: VehicleStatus::Maintenance->value,
        ));
    }
}
