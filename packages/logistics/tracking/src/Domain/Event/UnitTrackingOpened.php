<?php

declare(strict_types=1);

namespace Logistics\Tracking\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * Nació el seguimiento de un bulto: su read model per-bulto empieza a existir dentro del envío.
 */
final readonly class UnitTrackingOpened extends BaseDomainEvent
{
    public function __construct(
        string $unitTrackingId,
        public string $shipmentId,
        public string $unitId,
        public string $status,
    ) {
        parent::__construct($unitTrackingId);
    }

    public function eventName(): string
    {
        return 'tracking.unit_tracking_opened';
    }

    public function payload(): array
    {
        return [
            'unit_tracking_id' => $this->aggregateId,
            'shipment_id' => $this->shipmentId,
            'unit_id' => $this->unitId,
            'status' => $this->status,
        ];
    }
}
