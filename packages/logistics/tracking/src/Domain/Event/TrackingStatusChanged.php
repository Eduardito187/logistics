<?php

declare(strict_types=1);

namespace Logistics\Tracking\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * El estado visible del envío cambió. Es el hecho que, al integrar, dispara la notificación al cliente
 * (el canal lo elige otro contexto: tracking solo informa que el estado cambió, no cómo avisar).
 */
final readonly class TrackingStatusChanged extends BaseDomainEvent
{
    public function __construct(
        string $trackingId,
        public string $shipmentId,
        public string $previousStatus,
        public string $status,
    ) {
        parent::__construct($trackingId);
    }

    public function eventName(): string
    {
        return 'tracking.status_changed';
    }

    public function payload(): array
    {
        return [
            'tracking_id' => $this->aggregateId,
            'shipment_id' => $this->shipmentId,
            'previous_status' => $this->previousStatus,
            'status' => $this->status,
        ];
    }
}
