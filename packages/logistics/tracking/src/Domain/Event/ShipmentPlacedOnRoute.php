<?php

declare(strict_types=1);

namespace Logistics\Tracking\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * El envío quedó ubicado en la ruta publicada del camión: se conoce su posición en la secuencia y, por
 * ende, cuántas paradas faltan antes de la del cliente.
 *
 * Es el hecho que, al integrar, permite avisar al cliente "tu pedido está a N paradas". La ruta la
 * publica el contexto planning; tracking la recibe como dato por el puente de proyección, sin acoplarse
 * a su agregado (referencia la ruta y el envío solo por id string).
 */
final readonly class ShipmentPlacedOnRoute extends BaseDomainEvent
{
    public function __construct(
        string $trackingId,
        public string $shipmentId,
        public string $routeId,
        public int $routePosition,
        public int $stopsRemaining,
    ) {
        parent::__construct($trackingId);
    }

    public function eventName(): string
    {
        return 'tracking.shipment_placed_on_route';
    }

    public function payload(): array
    {
        return [
            'tracking_id' => $this->aggregateId,
            'shipment_id' => $this->shipmentId,
            'route_id' => $this->routeId,
            'route_position' => $this->routePosition,
            'stops_remaining' => $this->stopsRemaining,
        ];
    }
}
