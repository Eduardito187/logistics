<?php

declare(strict_types=1);

use Logistics\Execution\Domain\Event\DeliveryEvidenceCaptured;
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

    $evidence->applyValidation(EvidenceValidationStatus::Mismatch, 'número no coincide', new DateTimeImmutable('2026-11-05T13:00:00+00:00'));

    expect($evidence->validationStatus())->toBe(EvidenceValidationStatus::Mismatch)
        ->and($evidence->validationDetail())->toBe('número no coincide')
        ->and($evidence->validatedAt())->not->toBeNull()
        ->and($evidence->sha256())->toBe(hash('sha256', 'file-bytes'));
});
