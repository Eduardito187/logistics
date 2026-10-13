<?php

declare(strict_types=1);

namespace Logistics\Fleet\Domain\Model;

use DateTimeImmutable;
use Logistics\Fleet\Domain\Event\PreTripChecklistConducted;
use Logistics\Fleet\Domain\ValueObject\DriverId;
use Logistics\Fleet\Domain\ValueObject\PreTripChecklist;
use Logistics\Fleet\Domain\ValueObject\PreTripInspectionId;
use Logistics\Fleet\Domain\ValueObject\VehicleId;
use Logistics\Shared\Domain\Aggregate\AggregateRoot;

/**
 * Inspección pre-salida: el registro de que un chofer verificó un vehículo antes de salir a ruta.
 *
 * Es el checklist pre-salida "ejecutado": lleva qué vehículo y qué chofer, los ítems verificados (VO
 * PreTripChecklist) y la fecha. Está aprobada (apta para salir) solo si el checklist quedó completo;
 * si queda algún ítem sin verificar, la inspección no pasa y la tripulación no debería salir.
 */
final class PreTripInspection extends AggregateRoot
{
    private function __construct(
        private readonly PreTripInspectionId $id,
        private readonly VehicleId $vehicleId,
        private readonly DriverId $driverId,
        private readonly PreTripChecklist $checklist,
        private readonly DateTimeImmutable $performedOn,
        private readonly ?string $notes,
    ) {}

    /**
     * Registra una inspección pre-salida ya ejecutada (el chofer marcó los ítems en la app).
     */
    public static function conduct(
        PreTripInspectionId $id,
        VehicleId $vehicleId,
        DriverId $driverId,
        PreTripChecklist $checklist,
        DateTimeImmutable $performedOn,
        ?string $notes = null,
    ): self {
        $inspection = new self($id, $vehicleId, $driverId, $checklist, $performedOn, $notes);
        $inspection->recordEvent(new PreTripChecklistConducted(
            $id->toString(),
            $vehicleId->toString(),
            $driverId->toString(),
            $checklist->toArray(),
            $checklist->isComplete(),
            $performedOn->format('Y-m-d'),
        ));

        return $inspection;
    }

    /**
     * Reconstituye una inspección desde persistencia, sin grabar eventos.
     */
    public static function reconstitute(
        PreTripInspectionId $id,
        VehicleId $vehicleId,
        DriverId $driverId,
        PreTripChecklist $checklist,
        DateTimeImmutable $performedOn,
        ?string $notes,
    ): self {
        return new self($id, $vehicleId, $driverId, $checklist, $performedOn, $notes);
    }

    /**
     * ¿Aprobó? Apta para salir a ruta solo si todos los ítems quedaron verificados.
     */
    public function passed(): bool
    {
        return $this->checklist->isComplete();
    }

    public function id(): PreTripInspectionId
    {
        return $this->id;
    }

    public function vehicleId(): VehicleId
    {
        return $this->vehicleId;
    }

    public function driverId(): DriverId
    {
        return $this->driverId;
    }

    public function checklist(): PreTripChecklist
    {
        return $this->checklist;
    }

    public function performedOn(): DateTimeImmutable
    {
        return $this->performedOn;
    }

    public function notes(): ?string
    {
        return $this->notes;
    }
}
