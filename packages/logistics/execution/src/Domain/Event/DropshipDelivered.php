<?php

declare(strict_types=1);

namespace Logistics\Execution\Domain\Event;

use DateTimeImmutable;
use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * Un envío DROPSHIP se dio por entregado mediante la CONFIRMACIÓN del seller (o del transportista
 * tercero), no por una prueba de entrega propia de Dismac. Es el desenlace terminal de la rama dropship
 * sin escaneos propios; equivale a ShipmentDelivered para el flujo propio.
 */
final readonly class DropshipDelivered extends BaseDomainEvent
{
    public function __construct(
        string $deliveryId,
        public string $shipmentId,
        public string $handling,
        public ?string $reference,
        DateTimeImmutable $occurredAt,
    ) {
        parent::__construct($deliveryId, null, $occurredAt);
    }

    public function eventName(): string
    {
        return 'execution.dropship_delivered';
    }

    public function payload(): array
    {
        return [
            'delivery_id' => $this->aggregateId,
            'shipment_id' => $this->shipmentId,
            'handling' => $this->handling,
            'reference' => $this->reference,
        ];
    }
}
