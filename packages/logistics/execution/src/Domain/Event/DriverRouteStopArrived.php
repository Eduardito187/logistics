<?php

declare(strict_types=1);

namespace Logistics\Execution\Domain\Event;

use DateTimeImmutable;
use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * El camión LLEGÓ a una parada (geocerca de la app o marcado manual). Solo se emite la primera llegada.
 * Es el prerrequisito de los KPIs de tiempo en parada y puntualidad (avg_stop_minutes / on_time_rate).
 */
final readonly class DriverRouteStopArrived extends BaseDomainEvent
{
    public function __construct(
        string $routeId,
        public string $vehicleId,
        public string $shipmentId,
        public int $sequence,
        public string $source,
        public ?float $lat,
        public ?float $lng,
        public ?string $etaAt,
        DateTimeImmutable $occurredAt,
    ) {
        parent::__construct($routeId, null, $occurredAt);
    }

    public function eventName(): string
    {
        return 'execution.stop_arrived';
    }

    public function payload(): array
    {
        return [
            'route_id' => $this->aggregateId,
            'vehicle_id' => $this->vehicleId,
            'shipment_id' => $this->shipmentId,
            'sequence' => $this->sequence,
            'source' => $this->source,
            'lat' => $this->lat,
            'lng' => $this->lng,
            'eta_at' => $this->etaAt,
            'occurred_at' => $this->occurredAt->format(DATE_ATOM),
        ];
    }
}
