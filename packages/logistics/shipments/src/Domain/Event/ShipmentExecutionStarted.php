<?php

declare(strict_types=1);

namespace Logistics\Shipments\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * El envío entró en EJECUCIÓN: se preparó, se cargó o salió en un camión. El detalle de cada paso
 * (escaneos, vehículo, hora) lo lleva el contexto de ejecución; aquí solo se registra el cambio de estado.
 */
final readonly class ShipmentExecutionStarted extends BaseDomainEvent
{
    public function eventName(): string
    {
        return 'shipments.shipment_execution_started';
    }

    public function payload(): array
    {
        return [
            'shipment_id' => $this->aggregateId,
        ];
    }
}
