<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * El vehículo sostuvo una velocidad por encima del límite configurado durante el tiempo mínimo. Se emite
 * junto con `telemetry.alert_raised` (mismo agregado: la alerta de tipo speeding).
 */
final readonly class VehicleSpeedingSustained extends BaseDomainEvent
{
    public function __construct(
        string $aggregateId,
        public string $vehicleId,
        public ?float $lat,
        public ?float $lng,
        public ?float $speedKph,
        public ?float $limitKph,
        public ?int $sustainedSeconds,
        public string $speedingAtIso,
    ) {
        parent::__construct($aggregateId);
    }

    public function eventName(): string
    {
        return 'telemetry.vehicle_speeding_sustained';
    }

    public function payload(): array
    {
        return [
            'alert_id' => $this->aggregateId,
            'vehicle_id' => $this->vehicleId,
            'lat' => $this->lat,
            'lng' => $this->lng,
            'speed_kph' => $this->speedKph,
            'limit_kph' => $this->limitKph,
            'sustained_seconds' => $this->sustainedSeconds,
            'occurred_at' => $this->speedingAtIso,
        ];
    }
}
