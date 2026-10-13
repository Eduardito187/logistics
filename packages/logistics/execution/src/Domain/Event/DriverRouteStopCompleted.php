<?php

declare(strict_types=1);

namespace Logistics\Execution\Domain\Event;

use DateTimeImmutable;
use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * El chofer cerró con éxito una parada de la ruta y avanza a la siguiente. Lleva cuántas paradas quedan
 * (stops_remaining): es el hecho que consume el seguimiento para decirle al cliente a cuántas paradas
 * está su pedido.
 */
final readonly class DriverRouteStopCompleted extends BaseDomainEvent
{
    public function __construct(
        string $routeId,
        public string $vehicleId,
        public string $shipmentId,
        public int $sequence,
        public int $stopsRemaining,
        DateTimeImmutable $occurredAt,
    ) {
        parent::__construct($routeId, null, $occurredAt);
    }

    public function eventName(): string
    {
        return 'execution.route_stop_completed';
    }

    public function payload(): array
    {
        return [
            'route_id' => $this->aggregateId,
            'vehicle_id' => $this->vehicleId,
            'shipment_id' => $this->shipmentId,
            'sequence' => $this->sequence,
            'stops_remaining' => $this->stopsRemaining,
        ];
    }
}
