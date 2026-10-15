<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * Arrancó un trip: un vehículo empezó a reportar un tramo continuo de conducción.
 */
final readonly class TripStarted extends BaseDomainEvent
{
    public function __construct(
        string $aggregateId,
        public string $vehicleId,
        public ?string $routeId,
        public string $startedAt,
    ) {
        parent::__construct($aggregateId);
    }

    public function eventName(): string
    {
        return 'telemetry.trip_started';
    }

    public function payload(): array
    {
        return [
            'vehicle_id' => $this->vehicleId,
            'route_id' => $this->routeId,
            'started_at' => $this->startedAt,
        ];
    }
}
