<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Domain\Model;

use DateTimeImmutable;
use Logistics\FleetOps\Domain\Event\OdometerRecorded;
use Logistics\FleetOps\Domain\Exception\FleetOpsException;
use Logistics\FleetOps\Domain\ValueObject\Odometer;
use Logistics\FleetOps\Domain\ValueObject\OdometerReadingId;
use Logistics\FleetOps\Domain\ValueObject\OdometerSource;
use Logistics\Shared\Domain\Aggregate\AggregateRoot;

/**
 * Lectura puntual del odómetro de un vehículo, con su fuente y el momento de la lectura. Es el histórico
 * append-only que alimenta el odómetro actual del vehículo, el consumo y la utilización de la flota.
 *
 * El vehículo se referencia por su id como texto (UUID de fleet) sin FK dura: el contexto es desacoplable.
 */
final class OdometerReading extends AggregateRoot
{
    private function __construct(
        private readonly OdometerReadingId $id,
        private readonly string $vehicleId,
        private readonly Odometer $odometer,
        private readonly OdometerSource $source,
        private readonly DateTimeImmutable $readAt,
        private readonly ?string $note,
        private readonly ?string $routeId = null,
    ) {}

    /**
     * Registra una lectura de odómetro nueva.
     */
    public static function record(
        OdometerReadingId $id,
        string $vehicleId,
        Odometer $odometer,
        OdometerSource $source,
        DateTimeImmutable $readAt,
        ?string $note,
        ?string $routeId = null,
    ): self {
        $vehicleId = trim($vehicleId);
        if ($vehicleId === '') {
            throw FleetOpsException::because('La lectura de odómetro debe referenciar un vehículo.');
        }

        $routeId = $routeId !== null && trim($routeId) !== '' ? trim($routeId) : null;

        $reading = new self($id, $vehicleId, $odometer, $source, $readAt, $note, $routeId);

        $reading->recordEvent(new OdometerRecorded(
            $id->toString(),
            $vehicleId,
            $odometer->km,
            $source->value,
            $readAt->format(DateTimeImmutable::ATOM),
        ));

        return $reading;
    }

    /**
     * Reconstituye una lectura desde persistencia, sin grabar eventos (ya ocurrieron).
     */
    public static function reconstitute(
        OdometerReadingId $id,
        string $vehicleId,
        Odometer $odometer,
        OdometerSource $source,
        DateTimeImmutable $readAt,
        ?string $note,
        ?string $routeId = null,
    ): self {
        return new self($id, $vehicleId, $odometer, $source, $readAt, $note, $routeId);
    }

    public function id(): OdometerReadingId
    {
        return $this->id;
    }

    public function vehicleId(): string
    {
        return $this->vehicleId;
    }

    public function odometer(): Odometer
    {
        return $this->odometer;
    }

    public function source(): OdometerSource
    {
        return $this->source;
    }

    public function readAt(): DateTimeImmutable
    {
        return $this->readAt;
    }

    public function note(): ?string
    {
        return $this->note;
    }

    /** Ruta del chofer a la que pertenece la lectura (salida/llegada); null si no es de una ruta. */
    public function routeId(): ?string
    {
        return $this->routeId;
    }
}
