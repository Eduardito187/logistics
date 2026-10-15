<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Domain\Model;

use DateTimeImmutable;
use Logistics\FleetOps\Domain\Event\DepartureClosed;
use Logistics\FleetOps\Domain\Event\DepartureRegistered;
use Logistics\FleetOps\Domain\Exception\FleetOpsException;
use Logistics\FleetOps\Domain\ValueObject\DepartureId;
use Logistics\FleetOps\Domain\ValueObject\DepartureStatus;
use Logistics\FleetOps\Domain\ValueObject\Load;
use Logistics\FleetOps\Domain\ValueObject\Odometer;
use Logistics\Shared\Domain\Aggregate\AggregateRoot;

/**
 * Salida/despacho de un vehículo de flota propia: quién salió (conductor + ayudantes), a dónde, con qué
 * carga, con qué odómetro de salida y de regreso. Mientras está en ruta su estado es Out; al retornar se
 * cierra (Returned) y se calculan los km recorridos entre el odómetro de salida y el de regreso.
 *
 * El vehículo se referencia por su id como texto (UUID de fleet) sin FK dura: el contexto es desacoplable.
 */
final class Departure extends AggregateRoot
{
    /**
     * @param list<string> $helperIds
     */
    private function __construct(
        private readonly DepartureId $id,
        private readonly string $vehicleId,
        private readonly ?string $driverId,
        private readonly array $helperIds,
        private readonly ?string $destination,
        private readonly ?string $routeRef,
        private readonly Load $load,
        private readonly ?Odometer $odometerOut,
        private DepartureStatus $status,
        private ?Odometer $odometerIn,
        private readonly DateTimeImmutable $departedAt,
        private ?DateTimeImmutable $returnedAt,
        private readonly ?string $note,
    ) {}

    /**
     * Registra una salida nueva. Arranca en ruta (Out), sin odómetro ni fecha de regreso.
     *
     * @param list<string> $helperIds
     */
    public static function register(
        DepartureId $id,
        string $vehicleId,
        ?string $driverId,
        array $helperIds,
        ?string $destination,
        ?string $routeRef,
        Load $load,
        ?Odometer $odometerOut,
        DateTimeImmutable $departedAt,
        ?string $note,
    ): self {
        $vehicleId = trim($vehicleId);
        if ($vehicleId === '') {
            throw FleetOpsException::because('La salida debe referenciar un vehículo.');
        }

        $departure = new self(
            $id,
            $vehicleId,
            $driverId,
            array_values($helperIds),
            $destination,
            $routeRef,
            $load,
            $odometerOut,
            DepartureStatus::Out,
            null,
            $departedAt,
            null,
            $note,
        );

        $departure->recordEvent(new DepartureRegistered(
            $id->toString(),
            $vehicleId,
            $driverId,
            $departedAt->format(DateTimeImmutable::ATOM),
        ));

        return $departure;
    }

    /**
     * Reconstituye una salida desde persistencia, sin grabar eventos (ya ocurrieron).
     *
     * @param list<string> $helperIds
     */
    public static function reconstitute(
        DepartureId $id,
        string $vehicleId,
        ?string $driverId,
        array $helperIds,
        ?string $destination,
        ?string $routeRef,
        Load $load,
        ?Odometer $odometerOut,
        DepartureStatus $status,
        ?Odometer $odometerIn,
        DateTimeImmutable $departedAt,
        ?DateTimeImmutable $returnedAt,
        ?string $note,
    ): self {
        return new self(
            $id,
            $vehicleId,
            $driverId,
            array_values($helperIds),
            $destination,
            $routeRef,
            $load,
            $odometerOut,
            $status,
            $odometerIn,
            $departedAt,
            $returnedAt,
            $note,
        );
    }

    /**
     * Cierra la salida al retornar el vehículo: fija el odómetro de regreso y la fecha, pasa a Returned y
     * calcula los km recorridos. Solo se puede cerrar una salida en ruta; el odómetro de regreso no puede
     * ser menor al de salida (el odómetro no retrocede).
     */
    public function close(Odometer $odometerIn, DateTimeImmutable $returnedAt): void
    {
        if ($this->status !== DepartureStatus::Out) {
            throw FleetOpsException::because('La salida ya fue cerrada.');
        }
        if ($this->odometerOut !== null && $odometerIn->km < $this->odometerOut->km) {
            throw FleetOpsException::because('El odómetro de regreso no puede ser menor al de salida.');
        }

        $this->odometerIn = $odometerIn;
        $this->returnedAt = $returnedAt;
        $this->status = DepartureStatus::Returned;

        $kmTraveled = $this->kmTraveled();

        $this->recordEvent(new DepartureClosed(
            $this->id->toString(),
            $odometerIn->km,
            $kmTraveled,
            $returnedAt->format(DateTimeImmutable::ATOM),
        ));
    }

    /**
     * Km recorridos entre el odómetro de salida y el de regreso. Nulo si falta alguno de los dos.
     */
    public function kmTraveled(): ?int
    {
        if ($this->odometerOut === null || $this->odometerIn === null) {
            return null;
        }

        return $this->odometerIn->kmSince($this->odometerOut);
    }

    public function id(): DepartureId
    {
        return $this->id;
    }

    public function vehicleId(): string
    {
        return $this->vehicleId;
    }

    public function driverId(): ?string
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

    public function destination(): ?string
    {
        return $this->destination;
    }

    public function routeRef(): ?string
    {
        return $this->routeRef;
    }

    public function load(): Load
    {
        return $this->load;
    }

    public function odometerOut(): ?Odometer
    {
        return $this->odometerOut;
    }

    public function odometerIn(): ?Odometer
    {
        return $this->odometerIn;
    }

    public function status(): DepartureStatus
    {
        return $this->status;
    }

    public function departedAt(): DateTimeImmutable
    {
        return $this->departedAt;
    }

    public function returnedAt(): ?DateTimeImmutable
    {
        return $this->returnedAt;
    }

    public function note(): ?string
    {
        return $this->note;
    }
}
