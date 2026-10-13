<?php

declare(strict_types=1);

namespace Logistics\Shipments\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * Se recalculó la confianza de la dirección del envío (p. ej. tras geocodificar). Útil para auditar
 * cómo mejoró o empeoró la calidad de las coordenadas a lo largo del tiempo.
 */
final readonly class ShipmentAddressRescored extends BaseDomainEvent
{
    public function __construct(
        string $shipmentId,
        public int $addressConfidenceScore,
        public string $addressConfidenceLevel,
    ) {
        parent::__construct($shipmentId);
    }

    public function eventName(): string
    {
        return 'shipments.shipment_address_rescored';
    }

    public function payload(): array
    {
        return [
            'shipment_id' => $this->aggregateId,
            'address_confidence_score' => $this->addressConfidenceScore,
            'address_confidence_level' => $this->addressConfidenceLevel,
        ];
    }
}
