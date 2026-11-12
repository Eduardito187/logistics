<?php

declare(strict_types=1);

use Logistics\Execution\Domain\Event\DeliveryEvidenceCaptured;
use Logistics\Execution\Domain\Event\DeliveryEvidenceValidated;
use Logistics\Execution\Domain\Exception\ExecutionException;
use Logistics\Execution\Domain\Model\DeliveryEvidence;
use Logistics\Execution\Domain\ValueObject\CustomerIdentitySnapshot;
use Logistics\Execution\Domain\ValueObject\DeliveryEvidenceId;
use Logistics\Execution\Domain\ValueObject\DeviceContext;
use Logistics\Execution\Domain\ValueObject\EvidenceKind;
use Logistics\Execution\Domain\ValueObject\EvidenceValidationStatus;
use Logistics\Execution\Domain\ValueObject\GeoPoint;

// Agregado de evidencia anti-fraude: la captura es inmutable (con hash de integridad + auditoría) y graba
// el evento que dispara la validación asíncrona; solo evoluciona el estado de validación.

// Instante de validación usado en varios casos (un solo literal por archivo).
const DEVIDENCE_VALIDATED_AT = '2026-11-05T13:00:00+00:00';

function captureEvidence(?string $hash = null): DeliveryEvidence
{
    return DeliveryEvidence::capture(
        DeliveryEvidenceId::generate(),
        'SHIP-1',
        EvidenceKind::IdDocument,
        CustomerIdentitySnapshot::of('CUST-1', '1234567', 'Ana', '700'),
        'id_document',
        'id_document/SHIP-1/x.jpg',
        $hash ?? hash('sha256', 'file-bytes'),
        DeviceContext::of('dev-1', 'user-1'),
        GeoPoint::of(-17.8, -63.2),
        new DateTimeImmutable('2026-11-05T12:00:00+00:00'),
        'idem-1',
    );
}

it('captura con estado pending y graba el evento DeliveryEvidenceCaptured', function (): void {
    $evidence = captureEvidence();

    expect($evidence->validationStatus())->toBe(EvidenceValidationStatus::Pending)
        ->and($evidence->kind())->toBe(EvidenceKind::IdDocument)
        ->and($evidence->customer()->documentId)->toBe('1234567');

    $events = $evidence->releaseEvents();
    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(DeliveryEvidenceCaptured::class);
});

it('rechaza un hash que no es SHA-256 (64 hex)', function (): void {
    captureEvidence('no-es-un-hash');
})->throws(ExecutionException::class);

it('aplica el resultado de validación sin tocar la captura', function (): void {
    $evidence = captureEvidence();

    $evidence->applyValidation(EvidenceValidationStatus::Mismatch, 'número no coincide', new DateTimeImmutable(DEVIDENCE_VALIDATED_AT));

    expect($evidence->validationStatus())->toBe(EvidenceValidationStatus::Mismatch)
        ->and($evidence->validationDetail())->toBe('número no coincide')
        ->and($evidence->validatedAt())->not->toBeNull()
        ->and($evidence->sha256())->toBe(hash('sha256', 'file-bytes'));
});

it('graba DeliveryEvidenceValidated al cambiar el resultado de validación, una sola vez', function (): void {
    $evidence = captureEvidence();
    $evidence->releaseEvents();

    $at = new DateTimeImmutable(DEVIDENCE_VALIDATED_AT);
    $evidence->applyValidation(EvidenceValidationStatus::Ok, null, $at, 'evidence-sidecar');

    $events = $evidence->releaseEvents();
    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(DeliveryEvidenceValidated::class)
        ->and($events[0]->eventName())->toBe('execution.delivery_evidence_validated')
        ->and($events[0]->occurredAt())->toEqual($at)
        ->and($events[0]->payload())->toMatchArray([
            'evidence_id' => $evidence->id()->toString(),
            'shipment_id' => 'SHIP-1',
            'kind' => 'id_document',
            'validation_status' => 'ok',
            'previous_status' => 'pending',
            'validated_by' => 'evidence-sidecar',
            'occurred_at' => DEVIDENCE_VALIDATED_AT,
        ]);

    // Mismo resultado reenviado (reintento del validador): no se duplica el hecho.
    $evidence->applyValidation(EvidenceValidationStatus::Ok, null, $at, 'evidence-sidecar');
    expect($evidence->releaseEvents())->toBe([]);

    // Un "pending" no es una validación: tampoco graba.
    $other = captureEvidence();
    $other->releaseEvents();
    $other->applyValidation(EvidenceValidationStatus::Pending, null, $at);
    expect($other->releaseEvents())->toBe([]);
});
