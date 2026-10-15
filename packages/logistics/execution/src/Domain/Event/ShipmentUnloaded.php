<?php

declare(strict_types=1);

namespace Logistics\Execution\Domain\Event;

use DateTimeImmutable;
use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * Se registró la descarga guiada de un bulto en una parada (nodo o punto de la ruta). No es el
 * desenlace de la entrega: deja constancia de qué bajó y dónde. De acá se alimentan tracking y la
 * reconciliación de bultos descargados por error/faltantes.
 *
 * El nodo/parada se referencia por id string (no se acopla al contexto network ni a planning).
 */
final readonly class ShipmentUnloaded extends BaseDomainEvent
{
    public function __construct(
        string $deliveryId,
        public string $shipmentId,
        public string $nodeId,
        public string $unitId,
        DateTimeImmutable $occurredAt,
    ) {
        parent::__construct($deliveryId, null, $occurredAt);
    }

    public function eventName(): string
    {
        return 'execution.shipment_unloaded';
    }

    public function payload(): array
    {
        return [
            'delivery_id' => $this->aggregateId,
            'shipment_id' => $this->shipmentId,
            'node_id' => $this->nodeId,
            'unit_id' => $this->unitId,
        ];
    }
}
