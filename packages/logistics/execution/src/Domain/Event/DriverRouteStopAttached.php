<?php

declare(strict_types=1);

namespace Logistics\Execution\Domain\Event;

use DateTimeImmutable;
use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * Se agregó una parada a la ruta del chofer fuera del ensamblado inicial: la asignación manual de un
 * envío a un camión desde el andén (POST /app/orders/{n}/vehicle). Lleva el total de paradas resultante,
 * que es lo que el seguimiento usa para reflejar la hoja de ruta vigente.
 */
final readonly class DriverRouteStopAttached extends BaseDomainEvent
{
    public function __construct(
        string $routeId,
        public string $vehicleId,
        public string $shipmentId,
        public int $sequence,
        public int $totalStops,
        DateTimeImmutable $occurredAt,
    ) {
        parent::__construct($routeId, null, $occurredAt);
    }

    public function eventName(): string
    {
        return 'execution.route_stop_attached';
    }

    public function payload(): array
    {
        return [
            'route_id' => $this->aggregateId,
            'vehicle_id' => $this->vehicleId,
            'shipment_id' => $this->shipmentId,
            'sequence' => $this->sequence,
            'total_stops' => $this->totalStops,
        ];
    }
}
