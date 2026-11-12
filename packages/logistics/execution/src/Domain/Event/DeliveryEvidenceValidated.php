<?php

declare(strict_types=1);

namespace Logistics\Execution\Domain\Event;

use DateTimeImmutable;
use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * Una evidencia de entrega quedó VALIDADA (resultado del validador anti-fraude asíncrono: ok, review o
 * mismatch). Hecho inmutable que viaja por el outbox para que otros contextos reaccionen sin consultar a
 * execution; p. ej. el módulo de compensaciones aprueba solo un vale por daño cuando la evidencia `damage`
 * quedó `ok`.
 *
 * El payload NO lleva PII ni el detalle libre del validador (podría contener el número de documento): solo
 * referencias (ids), el tipo de evidencia, el resultado, el estado previo, quién validó y cuándo.
 */
final readonly class DeliveryEvidenceValidated extends BaseDomainEvent
{
    public function __construct(
        string $evidenceId,
        public string $shipmentId,
        public string $kind,
        public string $validationStatus,
        public string $previousStatus,
        public ?string $validatedBy,
        DateTimeImmutable $validatedAt,
    ) {
        parent::__construct($evidenceId, null, $validatedAt);
    }

    public function eventName(): string
    {
        return 'execution.delivery_evidence_validated';
    }

    public function payload(): array
    {
        return [
            'evidence_id' => $this->aggregateId,
            'shipment_id' => $this->shipmentId,
            'kind' => $this->kind,
            'validation_status' => $this->validationStatus,
            'previous_status' => $this->previousStatus,
            'validated_by' => $this->validatedBy,
            'occurred_at' => $this->occurredAt->format(DateTimeImmutable::ATOM),
        ];
    }
}
