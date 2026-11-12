<?php

declare(strict_types=1);

namespace Logistics\Execution\Domain\Event;

use DateTimeImmutable;
use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * El chofer INICIÓ la ruta del día (POST /app/vehicles/{id}/route/start): la ruta pasa a en curso. Lleva el
 * odómetro y la posición de salida (opcionales) y cuántas paradas tiene la jornada. Elegible para el
 * catálogo de avisos a SAP y para KPIs (hora real de salida).
 */
final readonly class DriverRouteStarted extends BaseDomainEvent
{
    public function __construct(
        string $routeId,
        public string $vehicleId,
        public string $routeDate,
        public int $totalStops,
        public int $stopsRemaining,
        public ?float $odometerKm,
        public ?float $lat,
        public ?float $lng,
        DateTimeImmutable $occurredAt,
    ) {
        parent::__construct($routeId, null, $occurredAt);
    }

    public function eventName(): string
    {
        return 'execution.driver_route_started';
    }

    public function payload(): array
    {
        return [
            'route_id' => $this->aggregateId,
            'vehicle_id' => $this->vehicleId,
            'route_date' => $this->routeDate,
            'total_stops' => $this->totalStops,
            'stops_remaining' => $this->stopsRemaining,
            'odometer_km' => $this->odometerKm,
            'lat' => $this->lat,
            'lng' => $this->lng,
            'occurred_at' => $this->occurredAt->format(DATE_ATOM),
        ];
    }
}
