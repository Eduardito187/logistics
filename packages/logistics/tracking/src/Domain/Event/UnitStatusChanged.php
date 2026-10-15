<?php

declare(strict_types=1);

namespace Logistics\Tracking\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * El estado visible de un bulto cambió. Es el hecho que alimenta la reconciliación de bultos (qué se
 * cargó y qué se descargó) y, al integrar, el detalle per-bulto que ve el cliente.
 */
final readonly class UnitStatusChanged extends BaseDomainEvent
{
    public function __construct(
        string $unitTrackingId,
        public string $shipmentId,
        public string $unitId,
        public string $previousStatus,
        public string $status,
    ) {
        parent::__construct($unitTrackingId);
    }

    public function eventName(): string
    {
        return 'tracking.unit_status_changed';
    }

    public function payload(): array
    {
        return [
            'unit_tracking_id' => $this->aggregateId,
            'shipment_id' => $this->shipmentId,
            'unit_id' => $this->unitId,
            'previous_status' => $this->previousStatus,
            'status' => $this->status,
        ];
    }
}
