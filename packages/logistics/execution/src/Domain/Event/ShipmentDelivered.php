<?php

declare(strict_types=1);

namespace Logistics\Execution\Domain\Event;

use DateTimeImmutable;
use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * La entrega se completó con prueba de entrega (QR del cliente o firma, y opcionalmente foto).
 * De acá se alimentan tracking y notifications.
 */
final readonly class ShipmentDelivered extends BaseDomainEvent
{
    public function __construct(
        string $deliveryId,
        public string $shipmentId,
        public string $confirmation,
        public ?string $photoUrl,
        DateTimeImmutable $occurredAt,
    ) {
        parent::__construct($deliveryId, null, $occurredAt);
    }

    public function eventName(): string
    {
        return 'execution.shipment_delivered';
    }

    public function payload(): array
    {
        return [
            'delivery_id' => $this->aggregateId,
            'shipment_id' => $this->shipmentId,
            'confirmation' => $this->confirmation,
            'photo_url' => $this->photoUrl,
        ];
    }
}
