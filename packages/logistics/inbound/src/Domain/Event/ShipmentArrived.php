<?php

declare(strict_types=1);

namespace Logistics\Inbound\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * El camión del seller se presentó en el almacén para la cita del ASN.
 */
final readonly class ShipmentArrived extends BaseDomainEvent
{
    public function __construct(
        string $aggregateId,
        public string $warehouseId,
        public ?string $carrierReference,
    ) {
        parent::__construct($aggregateId);
    }

    public function eventName(): string
    {
        return 'inbound.shipment_arrived';
    }

    public function payload(): array
    {
        return [
            'warehouse_id' => $this->warehouseId,
            'carrier_reference' => $this->carrierReference,
        ];
    }
}
