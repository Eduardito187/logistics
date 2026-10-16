<?php

declare(strict_types=1);

namespace Logistics\Execution\Domain\Event;

use DateTimeImmutable;
use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * El chofer reportó un inconveniente durante una entrega EN CURSO (no la cierra). Es un hecho inmutable
 * que viaja por el outbox para que la torre de control y las notificaciones reaccionen (avisar al cliente
 * de un retraso, reordenar la ruta, etc.). No cambia el estado de la entrega: solo deja constancia.
 */
final readonly class DeliveryIssueReported extends BaseDomainEvent
{
    public function __construct(
        string $issueId,
        public string $shipmentId,
        public string $type,
        public ?string $note,
        public ?float $lat,
        public ?float $lng,
        public ?string $photoPath,
        DateTimeImmutable $occurredAt,
    ) {
        parent::__construct($issueId, null, $occurredAt);
    }

    public function eventName(): string
    {
        return 'execution.delivery_issue_reported';
    }

    public function payload(): array
    {
        return [
            'issue_id' => $this->aggregateId,
            'shipment_id' => $this->shipmentId,
            'type' => $this->type,
            'note' => $this->note,
            'lat' => $this->lat,
            'lng' => $this->lng,
            'photo_path' => $this->photoPath,
        ];
    }
}
