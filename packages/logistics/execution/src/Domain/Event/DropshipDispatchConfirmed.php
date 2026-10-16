<?php

declare(strict_types=1);

namespace Logistics\Execution\Domain\Event;

use DateTimeImmutable;
use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * El seller (o el transportista tercero) confirmó que despachó un envío DROPSHIP. Marca el inicio del
 * tránsito sin que haya habido preparación/carga propias de Dismac. De acá se alimentan tracking y
 * notifications, igual que los hitos de un envío propio.
 */
final readonly class DropshipDispatchConfirmed extends BaseDomainEvent
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
        return 'execution.dropship_dispatch_confirmed';
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
