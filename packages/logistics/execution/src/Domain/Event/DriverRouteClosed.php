<?php

declare(strict_types=1);

namespace Logistics\Execution\Domain\Event;

use DateTimeImmutable;
use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * El chofer CERRÓ la ruta del día (POST /app/vehicles/{id}/route/close). No exige que todas las paradas
 * estén entregadas: las pendientes quedan tal cual y se informan en `pending_stops` para que operaciones
 * (y SAP, si lo tiene configurado) sepan qué volvió sin entregarse.
 */
final readonly class DriverRouteClosed extends BaseDomainEvent
{
    public function __construct(
        string $routeId,
        public string $vehicleId,
        public string $routeDate,
        public int $totalStops,
        public int $completedStops,
        public int $pendingStops,
        public ?float $odometerKm,
        public ?float $lat,
        public ?float $lng,
        DateTimeImmutable $occurredAt,
    ) {
        parent::__construct($routeId, null, $occurredAt);
    }

    public function eventName(): string
    {
        return 'execution.driver_route_closed';
    }

    public function payload(): array
    {
        return [
            'route_id' => $this->aggregateId,
            'vehicle_id' => $this->vehicleId,
            'route_date' => $this->routeDate,
            'total_stops' => $this->totalStops,
            'completed_stops' => $this->completedStops,
            'pending_stops' => $this->pendingStops,
            'odometer_km' => $this->odometerKm,
            'lat' => $this->lat,
            'lng' => $this->lng,
            'occurred_at' => $this->occurredAt->format(DATE_ATOM),
        ];
    }
}
