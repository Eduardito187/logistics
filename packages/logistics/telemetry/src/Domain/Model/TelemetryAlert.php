<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Domain\Model;

use DateTimeImmutable;
use Logistics\Shared\Domain\Aggregate\AggregateRoot;
use Logistics\Telemetry\Domain\Event\AlertAcknowledged;
use Logistics\Telemetry\Domain\Event\AlertRaised;
use Logistics\Telemetry\Domain\Exception\TelemetryException;
use Logistics\Telemetry\Domain\ValueObject\AlertId;
use Logistics\Telemetry\Domain\ValueObject\AlertSeverity;
use Logistics\Telemetry\Domain\ValueObject\AlertType;
use Logistics\Telemetry\Domain\ValueObject\GeoPoint;

/**
 * Alerta de mal uso levantada sobre la telemetría de un vehículo (movimiento fuera de horario, exceso
 * de velocidad, incursión en zona prohibida, etc.).
 *
 * Es un hecho de negocio (a diferencia de un ping, que es solo una lectura): por eso es agregado y
 * graba eventos. La detección la hace el MisuseAnalyzer (puro) durante la ingesta; acá vive el hecho
 * ya detectado y su ciclo de reconocimiento (acknowledge). Referencia al vehículo/chofer por id como
 * texto para no acoplarse a los agregados de fleet. El punto geográfico es opcional (puede faltar).
 */
final class TelemetryAlert extends AggregateRoot
{
    /**
     * @param array<string, mixed> $detail
     */
    private function __construct(
        private readonly AlertId $id,
        private readonly string $vehicleId,
        private readonly ?string $driverId,
        private readonly AlertType $type,
        private readonly AlertSeverity $severity,
        private readonly ?GeoPoint $point,
        private readonly array $detail,
        private readonly DateTimeImmutable $occurredAt,
        private ?DateTimeImmutable $acknowledgedAt,
        private ?string $acknowledgedBy,
    ) {}

    /**
     * Levanta una alerta nueva. Arranca sin reconocer.
     *
     * @param array<string, mixed> $detail
     */
    public static function raise(
        AlertId $id,
        string $vehicleId,
        ?string $driverId,
        AlertType $type,
        AlertSeverity $severity,
        ?GeoPoint $point,
        array $detail,
        DateTimeImmutable $occurredAt,
    ): self {
        $vehicleId = trim($vehicleId);
        if ($vehicleId === '') {
            throw TelemetryException::because('La alerta requiere un vehículo.');
        }

        $alert = new self($id, $vehicleId, $driverId, $type, $severity, $point, $detail, $occurredAt, null, null);
        $alert->recordEvent(new AlertRaised(
            $id->toString(),
            $vehicleId,
            $type->value,
            $severity->value,
            $occurredAt->format(DateTimeImmutable::ATOM),
            $detail,
        ));

        return $alert;
    }

    /**
     * Reconstituye una alerta desde persistencia, sin grabar eventos (ya ocurrieron).
     *
     * @param array<string, mixed> $detail
     */
    public static function reconstitute(
        AlertId $id,
        string $vehicleId,
        ?string $driverId,
        AlertType $type,
        AlertSeverity $severity,
        ?GeoPoint $point,
        array $detail,
        DateTimeImmutable $occurredAt,
        ?DateTimeImmutable $acknowledgedAt,
        ?string $acknowledgedBy,
    ): self {
        return new self($id, $vehicleId, $driverId, $type, $severity, $point, $detail, $occurredAt, $acknowledgedAt, $acknowledgedBy);
    }

    /**
     * Reconoce la alerta. Idempotente: si ya estaba reconocida, no hace nada ni graba evento.
     */
    public function acknowledge(string $by, DateTimeImmutable $at): void
    {
        if ($this->acknowledgedAt !== null) {
            return;
        }
        $by = trim($by);
        if ($by === '') {
            throw TelemetryException::because('Reconocer una alerta requiere quién la reconoce.');
        }

        $this->acknowledgedAt = $at;
        $this->acknowledgedBy = $by;
        $this->recordEvent(new AlertAcknowledged(
            $this->id->toString(),
            $by,
            $at->format(DateTimeImmutable::ATOM),
        ));
    }

    public function isAcknowledged(): bool
    {
        return $this->acknowledgedAt !== null;
    }

    public function id(): AlertId
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

    public function type(): AlertType
    {
        return $this->type;
    }

    public function severity(): AlertSeverity
    {
        return $this->severity;
    }

    public function point(): ?GeoPoint
    {
        return $this->point;
    }

    /**
     * @return array<string, mixed>
     */
    public function detail(): array
    {
        return $this->detail;
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function acknowledgedAt(): ?DateTimeImmutable
    {
        return $this->acknowledgedAt;
    }

    public function acknowledgedBy(): ?string
    {
        return $this->acknowledgedBy;
    }
}
