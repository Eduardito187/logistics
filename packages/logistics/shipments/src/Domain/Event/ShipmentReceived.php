<?php

declare(strict_types=1);

namespace Logistics\Shipments\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * Un envío fue recibido y normalizado al modelo propio. Es el hecho de ingreso: de acá se alimentan
 * tracking, planificación y, más adelante, el outbox hacia otros contextos.
 */
final readonly class ShipmentReceived extends BaseDomainEvent
{
    public function __construct(
        string $shipmentId,
        public string $source,
        public string $externalId,
        public string $status,
        public int $addressConfidenceScore,
        public string $addressConfidenceLevel,
        public int $lineCount,
        public int $unitCount,
    ) {
        parent::__construct($shipmentId);
    }

    public function eventName(): string
    {
        return 'shipments.shipment_received';
    }

    public function payload(): array
    {
        return [
            'shipment_id' => $this->aggregateId,
            'source' => $this->source,
            'external_id' => $this->externalId,
            'status' => $this->status,
            'address_confidence_score' => $this->addressConfidenceScore,
            'address_confidence_level' => $this->addressConfidenceLevel,
            'line_count' => $this->lineCount,
            'unit_count' => $this->unitCount,
        ];
    }
}
