<?php

declare(strict_types=1);

namespace Logistics\Execution\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * Se armó la ruta del día de un camión: la secuencia ordenada de todas sus paradas quedó lista para que
 * el chofer la ejecute. Hecho base del seguimiento de "paradas restantes" (contexto Tracking).
 */
final readonly class DriverRouteAssembled extends BaseDomainEvent
{
    public function __construct(
        string $routeId,
        public string $vehicleId,
        public string $routeDate,
        public int $stopCount,
    ) {
        parent::__construct($routeId);
    }

    public function eventName(): string
    {
        return 'execution.driver_route_assembled';
    }

    public function payload(): array
    {
        return [
            'route_id' => $this->aggregateId,
            'vehicle_id' => $this->vehicleId,
            'route_date' => $this->routeDate,
            'stop_count' => $this->stopCount,
        ];
    }
}
