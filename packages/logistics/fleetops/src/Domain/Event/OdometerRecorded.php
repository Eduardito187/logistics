<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * Se registró una lectura de odómetro para un vehículo (de cualquier fuente).
 */
final readonly class OdometerRecorded extends BaseDomainEvent
{
    public function __construct(
        string $aggregateId,
        public string $vehicleId,
        public int $km,
        public string $source,
        // Momento de la lectura en ISO 8601; distinto de `occurredAt` del evento base.
        public string $readAtIso,
    ) {
        parent::__construct($aggregateId);
    }

    public function eventName(): string
    {
        return 'fleetops.odometer_recorded';
    }

    public function payload(): array
    {
        return [
            'vehicle_id' => $this->vehicleId,
            'km' => $this->km,
            'source' => $this->source,
            'read_at' => $this->readAtIso,
        ];
    }
}
