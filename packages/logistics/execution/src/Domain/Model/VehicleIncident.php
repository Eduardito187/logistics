<?php

declare(strict_types=1);

namespace Logistics\Execution\Domain\Model;

use DateTimeImmutable;
use Logistics\Execution\Domain\Event\VehicleIncidentReported;
use Logistics\Execution\Domain\Exception\ExecutionException;
use Logistics\Execution\Domain\ValueObject\DeviceContext;
use Logistics\Execution\Domain\ValueObject\GeoPoint;
use Logistics\Execution\Domain\ValueObject\IncidentSeverity;
use Logistics\Execution\Domain\ValueObject\IncidentType;
use Logistics\Execution\Domain\ValueObject\RouteDate;
use Logistics\Execution\Domain\ValueObject\RouteId;
use Logistics\Execution\Domain\ValueObject\VehicleIncidentId;
use Logistics\Shared\Domain\Aggregate\AggregateRoot;

/**
 * Incidente de camión reportado por el chofer en ruta (avería o accidente): un HECHO inmutable.
 *
 * No muta estado de negocio por sí mismo; solo deja constancia del incidente y graba el evento de
 * dominio que dispara la reacción automática aguas abajo (flota, planificación, notificaciones) por el
 * outbox. Referencia a la ruta y al vehículo por id string: no se acopla a fleet ni a planning.
 *
 * La lista de envíos afectados la calcula la ruta (sus paradas pendientes) y se la pasa el llamador: son
 * los únicos envíos que se reprograman o retrasan. Las paradas ya atendidas no entran acá.
 */
final class VehicleIncident extends AggregateRoot
{
    /**
     * @param list<string> $affectedShipmentIds
     */
    private function __construct(
        private readonly VehicleIncidentId $id,
        private readonly RouteId $routeId,
        private readonly string $vehicleId,
        private readonly IncidentSeverity $severity,
        private readonly IncidentType $type,
        private readonly ?string $note,
        private readonly ?GeoPoint $location,
        private readonly DeviceContext $reportedBy,
        private readonly array $affectedShipmentIds,
        private readonly ?RouteDate $rescheduleDate,
        private readonly ?int $delayMinutes,
        private readonly DateTimeImmutable $occurredAt,
        private readonly ?string $idempotencyKey,
    ) {}

    /**
     * Registra el incidente y graba el evento de dominio que dispara la reacción automática.
     *
     * @param list<string> $affectedShipmentIds Envíos pendientes al momento del incidente.
     */
    public static function report(
        VehicleIncidentId $id,
        RouteId $routeId,
        string $vehicleId,
        IncidentSeverity $severity,
        IncidentType $type,
        ?string $note,
        ?GeoPoint $location,
        DeviceContext $reportedBy,
        array $affectedShipmentIds,
        ?RouteDate $rescheduleDate,
        ?int $delayMinutes,
        DateTimeImmutable $occurredAt,
        ?string $idempotencyKey = null,
    ): self {
        $vehicle = trim($vehicleId);
        if ($vehicle === '') {
            throw ExecutionException::because('el incidente requiere el id del vehículo');
        }

        $incident = new self(
            $id,
            $routeId,
            $vehicle,
            $severity,
            $type,
            self::clean($note),
            $location,
            $reportedBy,
            array_values($affectedShipmentIds),
            $rescheduleDate,
            $delayMinutes,
            $occurredAt,
            $idempotencyKey,
        );

        $incident->recordEvent(new VehicleIncidentReported(
            $id->toString(),
            $routeId->toString(),
            $vehicle,
            $severity->value,
            $type->value,
            $incident->note,
            $location?->lat,
            $location?->lng,
            $incident->affectedShipmentIds,
            $rescheduleDate?->toString(),
            $delayMinutes,
            $occurredAt,
        ));

        return $incident;
    }

    public function id(): VehicleIncidentId
    {
        return $this->id;
    }

    public function routeId(): RouteId
    {
        return $this->routeId;
    }

    public function vehicleId(): string
    {
        return $this->vehicleId;
    }

    public function severity(): IncidentSeverity
    {
        return $this->severity;
    }

    public function type(): IncidentType
    {
        return $this->type;
    }

    public function note(): ?string
    {
        return $this->note;
    }

    public function location(): ?GeoPoint
    {
        return $this->location;
    }

    public function reportedBy(): DeviceContext
    {
        return $this->reportedBy;
    }

    /**
     * @return list<string>
     */
    public function affectedShipmentIds(): array
    {
        return $this->affectedShipmentIds;
    }

    public function rescheduleDate(): ?RouteDate
    {
        return $this->rescheduleDate;
    }

    public function delayMinutes(): ?int
    {
        return $this->delayMinutes;
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function idempotencyKey(): ?string
    {
        return $this->idempotencyKey;
    }

    /**
     * Normaliza un texto opcional: recorta espacios y convierte el vacío en null.
     */
    private static function clean(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
