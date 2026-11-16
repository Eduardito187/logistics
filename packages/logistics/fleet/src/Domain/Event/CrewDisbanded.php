<?php

declare(strict_types=1);

namespace Logistics\Fleet\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * Una tripulación quedó sin efecto sin que su chofer pase a otra: otro chofer se quedó con el camión.
 */
final readonly class CrewDisbanded extends BaseDomainEvent
{
    public function __construct(
        string $aggregateId,
        public string $driverId,
        public string $vehicleId,
        public string $assembledFor,
        public string $reason,
    ) {
        parent::__construct($aggregateId);
    }

    public function eventName(): string
    {
        return 'fleet.crew_disbanded';
    }

    public function payload(): array
    {
        return [
            'driver_id' => $this->driverId,
            'vehicle_id' => $this->vehicleId,
            'assembled_for' => $this->assembledFor,
            'reason' => $this->reason,
        ];
    }
}
