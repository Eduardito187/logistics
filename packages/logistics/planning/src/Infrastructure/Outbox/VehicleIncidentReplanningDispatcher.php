<?php

declare(strict_types=1);

namespace Logistics\Planning\Infrastructure\Outbox;

use Logistics\Outbox\Application\Contract\MessageDispatcher;
use Logistics\Outbox\Domain\OutboxMessage;
use Logistics\Planning\Application\Command\ScheduleReplan\ScheduleReplan;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Reacción de PLANIFICACIÓN ante un incidente de camión (execution.vehicle_incident_reported) que llega
 * por el outbox: si el incidente es MAYOR, los envíos que seguían pendientes (los afectados) se encolan
 * para REPLANIFICAR el día siguiente (la fecha ya viene resuelta en el evento). La planificación de ese
 * día rearmará sus planes.
 *
 * Un incidente MENOR no replanifica: la ruta continúa con retraso (las ETAs ya se empujaron en
 * ejecución), así que se ignora.
 *
 * Idempotente: ScheduleReplan es idempotente por envío+fecha, de modo que la reentrega at-least-once del
 * outbox no duplica el backlog. Encola el comando por el CommandBus (sin I/O síncrono acá).
 */
final readonly class VehicleIncidentReplanningDispatcher implements MessageDispatcher
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

        if (($payload['severity'] ?? null) !== 'MAJOR') {
            return;
        }

        $rescheduleDate = $payload['reschedule_date'] ?? null;
        if (!is_string($rescheduleDate) || $rescheduleDate === '') {
            return;
        }

        /** @var mixed $shipmentIds */
        $shipmentIds = $payload['affected_shipment_ids'] ?? [];
        if (!is_array($shipmentIds) || $shipmentIds === []) {
            return;
        }

        /** @var list<string> $ids */
        $ids = array_values(array_filter(
            array_map(static fn (mixed $id): string => is_string($id) ? $id : '', $shipmentIds),
            static fn (string $id): bool => $id !== '',
        ));

        if ($ids === []) {
            return;
        }

        $sourceRouteId = isset($payload['route_id']) && is_string($payload['route_id']) ? $payload['route_id'] : null;

        $this->commandBus->dispatch(new ScheduleReplan(
            shipmentIds: $ids,
            planDate: $rescheduleDate,
            reason: 'vehicle_incident',
            sourceRouteId: $sourceRouteId,
        ));
    }
}
