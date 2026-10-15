<?php

declare(strict_types=1);

namespace Logistics\Execution\Infrastructure\Persistence\Eloquent;

use DateTimeInterface;
use Logistics\Execution\Domain\Contract\VehicleIncidentRepository;
use Logistics\Execution\Domain\Model\VehicleIncident;
use Logistics\Execution\Domain\ValueObject\VehicleIncidentId;

/**
 * Implementación Eloquent del repositorio de incidentes de camión.
 *
 * El incidente es un hecho inmutable: save() inserta la fila (upsert por id para tolerar reintentos del
 * mismo agregado). Toda la conversión a primitivos vive acá; el dominio no conoce la forma de la tabla.
 */
final class EloquentVehicleIncidentRepository implements VehicleIncidentRepository
{
    public function nextIdentity(): VehicleIncidentId
    {
        return VehicleIncidentId::generate();
    }

    public function save(VehicleIncident $incident): void
    {
        VehicleIncidentRecord::query()->updateOrCreate(
            ['id' => $incident->id()->toString()],
            [
                'route_id' => $incident->routeId()->toString(),
                'vehicle_id' => $incident->vehicleId(),
                'severity' => $incident->severity()->value,
                'type' => $incident->type()->value,
                'note' => $incident->note(),
                'lat' => $incident->location()?->lat,
                'lng' => $incident->location()?->lng,
                'reported_by_user' => $incident->reportedBy()->userId,
                'reported_by_device' => $incident->reportedBy()->deviceId,
                'affected_shipment_ids' => $incident->affectedShipmentIds(),
                'reschedule_date' => $incident->rescheduleDate()?->toString(),
                'delay_minutes' => $incident->delayMinutes(),
                'occurred_at' => $incident->occurredAt()->format(DateTimeInterface::ATOM),
                'idempotency_key' => $incident->idempotencyKey(),
            ],
        );
    }

    public function existsByIdempotencyKey(?string $idempotencyKey): bool
    {
        if ($idempotencyKey === null || trim($idempotencyKey) === '') {
            return false;
        }

        return VehicleIncidentRecord::query()
            ->where('idempotency_key', trim($idempotencyKey))
            ->exists();
    }
}
