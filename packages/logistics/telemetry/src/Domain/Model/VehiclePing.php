<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Domain\Model;

use DateTimeImmutable;
use Logistics\Telemetry\Domain\ValueObject\GeoPoint;
use Logistics\Telemetry\Domain\ValueObject\Heading;
use Logistics\Telemetry\Domain\ValueObject\PingId;
use Logistics\Telemetry\Domain\ValueObject\PingSource;
use Logistics\Telemetry\Domain\ValueObject\Speed;
use Logistics\Telemetry\Domain\ValueObject\TripId;

/**
 * Una lectura de telemetría de un vehículo en un instante: posición y, si viene, velocidad, rumbo,
 * precisión, encendido y odómetro.
 *
 * A PROPÓSITO NO extiende AggregateRoot y NO graba eventos de dominio: el volumen es de millones de
 * pings y emitir un evento por lectura ahogaría el outbox. Los hechos de negocio que sí interesan
 * (un trip que arranca, uno que termina, una alerta) viven en los agregados Trip/TelemetryAlert, no en
 * cada ping. El ping es un dato append-only inmutable.
 *
 * Es `final readonly`: una lectura no cambia una vez tomada. `withTripId` devuelve una copia asociada a
 * un trip, porque el trip se resuelve durante la ingesta (ver TripTracker), después de construir el ping.
 */
final readonly class VehiclePing
{
    private function __construct(
        private PingId $id,
        private string $vehicleId,
        private ?string $driverId,
        private ?string $deviceId,
        private ?string $routeId,
        private GeoPoint $point,
        private ?Speed $speed,
        private ?Heading $heading,
        private ?float $accuracyM,
        private ?bool $ignition,
        private ?int $odometerM,
        private DateTimeImmutable $recordedAt,
        private DateTimeImmutable $receivedAt,
        private PingSource $source,
        private ?TripId $tripId,
    ) {}

    /**
     * Registra una lectura nueva recién recibida.
     */
    public static function record(
        PingId $id,
        string $vehicleId,
        ?string $driverId,
        ?string $deviceId,
        ?string $routeId,
        GeoPoint $point,
        ?Speed $speed,
        ?Heading $heading,
        ?float $accuracyM,
        ?bool $ignition,
        ?int $odometerM,
        DateTimeImmutable $recordedAt,
        DateTimeImmutable $receivedAt,
        PingSource $source,
        ?TripId $tripId = null,
    ): self {
        return new self(
            $id,
            $vehicleId,
            $driverId,
            $deviceId,
            $routeId,
            $point,
            $speed,
            $heading,
            $accuracyM,
            $ignition,
            $odometerM,
            $recordedAt,
            $receivedAt,
            $source,
            $tripId,
        );
    }

    /**
     * Reconstituye una lectura desde persistencia.
     */
    public static function reconstitute(
        PingId $id,
        string $vehicleId,
        ?string $driverId,
        ?string $deviceId,
        ?string $routeId,
        GeoPoint $point,
        ?Speed $speed,
        ?Heading $heading,
        ?float $accuracyM,
        ?bool $ignition,
        ?int $odometerM,
        DateTimeImmutable $recordedAt,
        DateTimeImmutable $receivedAt,
        PingSource $source,
        ?TripId $tripId,
    ): self {
        return new self(
            $id,
            $vehicleId,
            $driverId,
            $deviceId,
            $routeId,
            $point,
            $speed,
            $heading,
            $accuracyM,
            $ignition,
            $odometerM,
            $recordedAt,
            $receivedAt,
            $source,
            $tripId,
        );
    }

    /**
     * Devuelve una copia de la lectura asociada al trip indicado. El trip se resuelve durante la ingesta.
     */
    public function withTripId(TripId $tripId): self
    {
        return new self(
            $this->id,
            $this->vehicleId,
            $this->driverId,
            $this->deviceId,
            $this->routeId,
            $this->point,
            $this->speed,
            $this->heading,
            $this->accuracyM,
            $this->ignition,
            $this->odometerM,
            $this->recordedAt,
            $this->receivedAt,
            $this->source,
            $tripId,
        );
    }

    public function id(): PingId
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

    public function deviceId(): ?string
    {
        return $this->deviceId;
    }

    public function routeId(): ?string
    {
        return $this->routeId;
    }

    public function point(): GeoPoint
    {
        return $this->point;
    }

    public function speed(): ?Speed
    {
        return $this->speed;
    }

    public function heading(): ?Heading
    {
        return $this->heading;
    }

    public function accuracyM(): ?float
    {
        return $this->accuracyM;
    }

    public function ignition(): ?bool
    {
        return $this->ignition;
    }

    public function odometerM(): ?int
    {
        return $this->odometerM;
    }

    public function recordedAt(): DateTimeImmutable
    {
        return $this->recordedAt;
    }

    public function receivedAt(): DateTimeImmutable
    {
        return $this->receivedAt;
    }

    public function source(): PingSource
    {
        return $this->source;
    }

    public function tripId(): ?TripId
    {
        return $this->tripId;
    }
}
