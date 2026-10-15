<?php

declare(strict_types=1);

namespace Logistics\Fleet\Domain\Model;

use DateTimeImmutable;
use Logistics\Fleet\Domain\Event\CrewAssembled;
use Logistics\Fleet\Domain\Exception\FleetException;
use Logistics\Fleet\Domain\ValueObject\CrewId;
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
