<?php

declare(strict_types=1);

namespace Logistics\Execution\Domain\Event;

use DateTimeImmutable;
use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * Se SACÓ una parada de la ruta del chofer porque el envío se reasignó a OTRO camión (el pedido pasó al
 * último camión asignado). Solo ocurre con paradas aún PENDIENTES: una ya atendida (entregada/fallida) no
 * se desengancha. Deja la hoja de ruta del camión viejo consistente con la entrega (que ya se movió).
 */
final readonly class DriverRouteStopDetached extends BaseDomainEvent
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
        return 'execution.route_stop_detached';
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
