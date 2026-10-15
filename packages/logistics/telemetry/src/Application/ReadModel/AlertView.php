<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Application\ReadModel;

use DateTimeImmutable;
use Logistics\Telemetry\Domain\Model\TelemetryAlert;

/**
 * Proyección de lectura de una alerta para listados, admin y API. Primitivos: no expone objetos de
 * dominio. Las fechas salen en ISO 8601 (ATOM).
 */
final readonly class AlertView
{
    /**
     * @param array<string, mixed> $detail
     */
    private function __construct(
        public string $id,
        public string $vehicleId,
        public ?string $driverId,
        public string $type,
        public string $severity,
        public ?float $lat,
        public ?float $lng,
        public array $detail,
        public string $occurredAt,
        public ?string $acknowledgedAt,
        public ?string $acknowledgedBy,
    ) {}

    public static function fromDomain(TelemetryAlert $alert): self
    {
        $point = $alert->point();
        $acknowledgedAt = $alert->acknowledgedAt();

        return new self(
            $alert->id()->toString(),
            $alert->vehicleId(),
            $alert->driverId(),
            $alert->type()->value,
            $alert->severity()->value,
            $point?->lat,
            $point?->lng,
            $alert->detail(),
            $alert->occurredAt()->format(DateTimeImmutable::ATOM),
            $acknowledgedAt?->format(DateTimeImmutable::ATOM),
            $alert->acknowledgedBy(),
        );
    }

    /**
     * @return array{id: string, vehicle_id: string, driver_id: string|null, type: string, severity: string, lat: float|null, lng: float|null, detail: array<string, mixed>, occurred_at: string, acknowledged_at: string|null, acknowledged_by: string|null}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'vehicle_id' => $this->vehicleId,
            'driver_id' => $this->driverId,
            'type' => $this->type,
            'severity' => $this->severity,
            'lat' => $this->lat,
            'lng' => $this->lng,
            'detail' => $this->detail,
            'occurred_at' => $this->occurredAt,
            'acknowledged_at' => $this->acknowledgedAt,
            'acknowledged_by' => $this->acknowledgedBy,
        ];
    }
}
