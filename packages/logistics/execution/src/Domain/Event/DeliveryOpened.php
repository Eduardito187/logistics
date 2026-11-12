<?php

declare(strict_types=1);

namespace Logistics\Execution\Domain\Event;

use DateTimeImmutable;
use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * Se abrió la entrega de un envío al asignarle el camión de reparto (flujo vigente: asignar vehículo ES
 * preparar). Marca que el envío entró en EJECUCIÓN: lo consume el contexto shipments para avanzar su estado
 * (PLANNED/RECEIVED → IN_EXECUTION), de modo que deja de aparecer en el worklist "pedidos por preparar".
 * Equivale, en el flujo nuevo, al hecho que antes disparaba el escaneo de preparación.
 */
final readonly class DeliveryOpened extends BaseDomainEvent
{
    public function __construct(
        string $deliveryId,
        public string $shipmentId,
        public string $vehicleId,
        DateTimeImmutable $occurredAt,
    ) {
        parent::__construct($deliveryId, null, $occurredAt);
    }

    public function eventName(): string
    {
        return 'execution.delivery_opened';
    }

    public function payload(): array
    {
        return [
            'delivery_id' => $this->aggregateId,
            'shipment_id' => $this->shipmentId,
            'vehicle_id' => $this->vehicleId,
        ];
    }
}
