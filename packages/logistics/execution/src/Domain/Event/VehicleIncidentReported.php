<?php

declare(strict_types=1);

namespace Logistics\Execution\Domain\Event;

use DateTimeImmutable;
use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * El chofer reportó un incidente (avería o accidente) con su camión en ruta. Es el hecho inmutable que
 * dispara la reacción automática aguas abajo, por el outbox:
 *
 * - Flota: si es MAYOR, el vehículo pasa a fuera de servicio (mantenimiento).
 * - Planificación: si es MAYOR, los envíos pendientes se marcan para replanificar el día siguiente.
 * - Notificaciones: aviso al supervisor y a los clientes afectados (reprogramado / en camino con retraso).
 *
 * Lleva la lista de envíos afectados (las paradas que seguían pendientes al momento del incidente), la
 * fecha de reprogramación (solo MAYOR) y los minutos de retraso aplicados (solo MENOR). Las paradas ya
 * entregadas o fallidas no entran en la lista: no se tocan.
 */
final readonly class VehicleIncidentReported extends BaseDomainEvent
{
    /**
     * @param list<string> $affectedShipmentIds Envíos pendientes al momento del incidente (reprogramados/retrasados).
     */
    public function __construct(
        string $incidentId,
        public string $routeId,
        public string $vehicleId,
        public string $severity,
        public string $type,
        public ?string $note,
        public ?float $lat,
        public ?float $lng,
        public array $affectedShipmentIds,
        public ?string $rescheduleDate,
        public ?int $delayMinutes,
        DateTimeImmutable $occurredAt,
    ) {
        parent::__construct($incidentId, null, $occurredAt);
    }

    public function eventName(): string
    {
        return 'execution.vehicle_incident_reported';
    }

    public function payload(): array
    {
        return [
            'incident_id' => $this->aggregateId,
            'route_id' => $this->routeId,
            'vehicle_id' => $this->vehicleId,
            'severity' => $this->severity,
            'type' => $this->type,
            'note' => $this->note,
            'lat' => $this->lat,
            'lng' => $this->lng,
            'affected_shipment_ids' => $this->affectedShipmentIds,
            'reschedule_date' => $this->rescheduleDate,
            'delay_minutes' => $this->delayMinutes,
        ];
    }
}
