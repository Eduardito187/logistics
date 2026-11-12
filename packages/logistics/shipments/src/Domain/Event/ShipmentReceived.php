<?php

declare(strict_types=1);

namespace Logistics\Shipments\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * Un envío fue recibido y normalizado al modelo propio. Es el hecho de ingreso: de acá se alimentan
 * tracking, planificación y, más adelante, el outbox hacia otros contextos.
 *
 * Lleva además la carga agregada del envío (Roadmap 02, Fase 2.1): peso (kg) y volumen (m³) totales a
 * partir de la foto de medidas de cada línea, y si alguna línea cayó al default global. Son null cuando
 * alguna línea no tiene foto (envíos armados fuera del ingreso estándar).
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
        public ?float $totalWeightKg = null,
        public ?float $totalVolumeM3 = null,
        public bool $measurementsDefaulted = false,
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
            'total_weight_kg' => $this->totalWeightKg,
            'total_volume_m3' => $this->totalVolumeM3,
            'measurements_defaulted' => $this->measurementsDefaulted,
        ];
    }
}
