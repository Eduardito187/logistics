<?php

declare(strict_types=1);

namespace Logistics\Execution\Domain\Event;

use DateTimeImmutable;
use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * El chofer cerró como fallida una parada de la ruta (intento fallido) y avanza a la siguiente. Lleva el
 * motivo y las paradas restantes, para el seguimiento y para reprogramar el pedido aguas abajo.
 */
final readonly class DriverRouteStopFailed extends BaseDomainEvent
{
    public function __construct(
        string $routeId,
        public string $vehicleId,
        public string $shipmentId,
        public int $sequence,
        public string $reason,
        public int $stopsRemaining,
        DateTimeImmutable $occurredAt,
    ) {
        parent::__construct($routeId, null, $occurredAt);
    }

    public function eventName(): string
    {
        return 'execution.route_stop_failed';
    }

    public function payload(): array
    {
        return [
            'route_id' => $this->aggregateId,
            'vehicle_id' => $this->vehicleId,
            'shipment_id' => $this->shipmentId,
            'sequence' => $this->sequence,
            'reason' => $this->reason,
            'stops_remaining' => $this->stopsRemaining,
        ];
    }
}
