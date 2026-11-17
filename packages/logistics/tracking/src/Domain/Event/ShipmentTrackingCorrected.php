<?php

declare(strict_types=1);

namespace Logistics\Tracking\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * Un operador corrigió a MANO el estado visible del seguimiento (bitácora/timeline del hub). Es un hecho
 * de AUDITORÍA: deja quién corrigió, desde/hacia qué estado, el motivo y el momento, aparte del avance de
 * estado en sí (que lo graba {@see TrackingStatusChanged}).
 *
 * `notifyCustomer` decide si la corrección debe avisarse al cliente: el fanout del outbox lo lee de la
 * carga para rutear (o no) el aviso hacia notificaciones. En false, el evento solo alimenta la bitácora.
 */
final readonly class ShipmentTrackingCorrected extends BaseDomainEvent
{
    public function __construct(
        string $trackingId,
        public ?string $shipmentId,
        public string $from,
        public string $to,
        public string $reason,
        public string $actorId,
        public string $actorName,
        public string $at,
        public bool $notifyCustomer,
        public ?string $orderReference = null,
    ) {
        parent::__construct($trackingId);
    }

    public function eventName(): string
    {
        return 'tracking.shipment_corrected';
    }

    public function payload(): array
    {
        return [
            'tracking_id' => $this->aggregateId,
            'shipment_id' => $this->shipmentId,
            'order_reference' => $this->orderReference,
            'from' => $this->from,
            'to' => $this->to,
            'reason' => $this->reason,
            'actor_id' => $this->actorId,
            'actor_name' => $this->actorName,
            'at' => $this->at,
            'notify_customer' => $this->notifyCustomer,
        ];
    }
}
