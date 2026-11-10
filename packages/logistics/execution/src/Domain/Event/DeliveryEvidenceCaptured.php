<?php

declare(strict_types=1);

namespace Logistics\Execution\Domain\Event;

use DateTimeImmutable;
use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * Se capturó una evidencia de entrega ligada al cliente (firma, documento, POD, daño). Hecho inmutable
 * que viaja por el outbox: lo consume el validador anti-fraude ASÍNCRONO (worker/sidecar on-premise) que
 * procesa la evidencia (OCR del documento, señal de firma) y escribe el resultado de validación de vuelta.
 *
 * El payload NO lleva PII pesada ni el archivo: solo las referencias (ids, hash) para que el validador
 * localice la evidencia. El número de documento del cliente va como referencia para el cruce on-premise.
 */
final readonly class DeliveryEvidenceCaptured extends BaseDomainEvent
{
    public function __construct(
        string $evidenceId,
        public string $shipmentId,
        public string $kind,
        public ?string $customerId,
        public ?string $customerDocumentId,
        public string $sha256,
        DateTimeImmutable $capturedAt,
    ) {
        parent::__construct($evidenceId, null, $capturedAt);
    }

    public function eventName(): string
    {
        return 'execution.delivery_evidence_captured';
    }

    public function payload(): array
    {
        return [
            'evidence_id' => $this->aggregateId,
            'shipment_id' => $this->shipmentId,
            'kind' => $this->kind,
            'customer_id' => $this->customerId,
            'customer_document_id' => $this->customerDocumentId,
            'sha256' => $this->sha256,
        ];
    }
}
