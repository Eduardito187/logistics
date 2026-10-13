<?php

declare(strict_types=1);

namespace Logistics\Tracking\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * Se abrió el seguimiento de un envío: nació su read model y su token público. De este hecho cuelga
 * la generación del enlace de rastreo que se le comparte al cliente.
 */
final readonly class TrackingOpened extends BaseDomainEvent
{
    public function __construct(
        string $trackingId,
        public string $shipmentId,
        public string $token,
        public string $status,
        public int $remainingStops,
    ) {
        parent::__construct($trackingId);
    }

    public function eventName(): string
    {
        return 'tracking.tracking_opened';
    }

    public function payload(): array
    {
        return [
            'tracking_id' => $this->aggregateId,
            'shipment_id' => $this->shipmentId,
            'token' => $this->token,
            'status' => $this->status,
            'remaining_stops' => $this->remainingStops,
        ];
    }
}
