<?php

declare(strict_types=1);

namespace Logistics\Fleet\Domain\Model;

use DateTimeImmutable;
use Logistics\Fleet\Domain\Event\CrewAssembled;
use Logistics\Fleet\Domain\Event\CrewDisbanded;
use Logistics\Fleet\Domain\Event\CrewVehicleChanged;
use Logistics\Fleet\Domain\Exception\FleetException;
use Logistics\Fleet\Domain\ValueObject\CrewId;
use Logistics\Fleet\Domain\ValueObject\CrewRetirementReason;
use Logistics\Fleet\Domain\ValueObject\DriverId;
use Logistics\Fleet\Domain\ValueObject\VehicleId;
use Logistics\Fleet\Domain\ValueObject\WorkShift;
use Logistics\Shared\Domain\Aggregate\AggregateRoot;

/**
 * Tripulación asignada: un chofer, cero o más ayudantes y el vehículo, para una fecha dada.
 *
 * Es el vínculo persona ↔ vehículo del día. No decide la ruta ni la carga; solo representa quién sale
 * con qué camión. Una tripulación sin chofer o sin vehículo no tiene sentido y se rechaza al armarla.
 * Opcionalmente lleva el turno de trabajo (horas de salida y regreso): cuando se fija, la asignación se
 * valida contra las reglas de turnos y descansos (ver CrewShiftScheduler).
 *
 * Regla del día: un chofer tiene un solo camión por día y un camión una sola tripulación por día. Cambiarle
 * el camión a un chofer no suma otra tripulación: la nueva REEMPLAZA a la anterior (ver assembleReplacing).
 */
final class Crew extends AggregateRoot
{
    /**
     * @param list<string> $helperIds
     */
    private function __construct(
        private readonly CrewId $id,
        private readonly DriverId $driverId,
        private readonly array $helperIds,
        private readonly VehicleId $vehicleId,
        private readonly DateTimeImmutable $assembledFor,
        private readonly ?WorkShift $shift,
    ) {}

    /**
     * Arma una tripulación. Valida que haya chofer y vehículo (requisito del dominio).
     *
     * @param list<string> $helperIds
     */
    public static function assemble(
        CrewId $id,
        ?DriverId $driverId,
        array $helperIds,
        ?VehicleId $vehicleId,
        DateTimeImmutable $assembledFor,
        ?WorkShift $shift = null,
    ): self {
        if (!$driverId instanceof DriverId) {
            throw FleetException::because('Una tripulación necesita un chofer.');
        }
        if (!$vehicleId instanceof VehicleId) {
            throw FleetException::because('Una tripulación necesita un vehículo.');
        }
        $helperIds = array_values(array_unique($helperIds));

        $crew = new self($id, $driverId, $helperIds, $vehicleId, $assembledFor, $shift);
        $crew->recordEvent(new CrewAssembled(
            $id->toString(),
            $driverId->toString(),
            $helperIds,
            $vehicleId->toString(),
            $assembledFor->format('Y-m-d'),
            $shift?->toArray(),
        ));

        return $crew;
    }

    /**
     * Arma la tripulación que REEMPLAZA a otra que queda sin efecto. Si es el mismo chofer con otro camión,
     * el hecho es «camión cambiado» (con el anterior y el nuevo); en cualquier otro caso (mismos camión y
     * chofer con otros ayudantes o turno, o cambio de chofer) es un armado común.
     *
     * @param list<string> $helperIds
     */
    public static function assembleReplacing(
        self $previous,
        CrewId $id,
        DriverId $driverId,
        array $helperIds,
        VehicleId $vehicleId,
        DateTimeImmutable $assembledFor,
        ?WorkShift $shift = null,
    ): self {
        $crew = self::assemble($id, $driverId, $helperIds, $vehicleId, $assembledFor, $shift);

        if (!$previous->driverId->equals($driverId) || $previous->vehicleId->equals($vehicleId)) {
            return $crew;
        }

        // Mismo chofer, otro camión: el armado se cuenta como cambio, no como asignación nueva.
        $crew->releaseEvents();
        $crew->recordEvent(new CrewVehicleChanged(
            $id->toString(),
            $previous->id->toString(),
            $driverId->toString(),
            $previous->vehicleId->toString(),
            $vehicleId->toString(),
            $crew->helperIds,
            $assembledFor->format('Y-m-d'),
            $shift?->toArray(),
        ));

        return $crew;
    }

    /**
     * ¿Esta tripulación ya es exactamente la pedida (mismo camión, mismos ayudantes, mismo turno)? Si lo es,
     * volver a armarla no cambia nada.
     *
     * @param list<string> $helperIds
     */
    public function holdsSameAssignment(VehicleId $vehicleId, array $helperIds, ?WorkShift $shift): bool
    {
        $mine = $this->helperIds;
        $theirs = array_values(array_unique($helperIds));
        sort($mine);
        sort($theirs);

        return $this->vehicleId->equals($vehicleId)
            && $mine === $theirs
            // El turno se compara por instante: la misma hora puede venir escrita con otro desfase.
            && $this->shift?->startsAt->getTimestamp() === $shift?->startsAt->getTimestamp()
            && $this->shift?->endsAt->getTimestamp() === $shift?->endsAt->getTimestamp();
    }

    /**
     * Deja constancia de que la tripulación quedó sin efecto sin que su chofer pase a otra.
     */
    public function disband(CrewRetirementReason $reason): void
    {
        $this->recordEvent(new CrewDisbanded(
            $this->id->toString(),
            $this->driverId->toString(),
            $this->vehicleId->toString(),
            $this->assembledFor->format('Y-m-d'),
            $reason->value,
        ));
    }

    /**
     * Reconstituye una tripulación desde persistencia, sin grabar eventos.
     *
     * @param list<string> $helperIds
     */
    public static function reconstitute(
        CrewId $id,
        DriverId $driverId,
        array $helperIds,
        VehicleId $vehicleId,
        DateTimeImmutable $assembledFor,
        ?WorkShift $shift = null,
    ): self {
        return new self($id, $driverId, array_values($helperIds), $vehicleId, $assembledFor, $shift);
    }

    public function id(): CrewId
    {
        return $this->id;
    }

    public function driverId(): DriverId
    {
        return $this->driverId;
    }

    /**
     * @return list<string>
     */
    public function helperIds(): array
    {
        return $this->helperIds;
    }

    public function vehicleId(): VehicleId
    {
        return $this->vehicleId;
    }

    public function assembledFor(): DateTimeImmutable
    {
        return $this->assembledFor;
    }

    /**
     * Turno de trabajo de la tripulación, o null si se armó sin fijar horas.
     */
    public function shift(): ?WorkShift
    {
        return $this->shift;
    }
}
