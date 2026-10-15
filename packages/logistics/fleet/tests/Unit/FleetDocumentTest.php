<?php

declare(strict_types=1);

use Logistics\Fleet\Domain\Event\DocumentRegistered;
use Logistics\Fleet\Domain\Model\FleetDocument;
use Logistics\Fleet\Domain\ValueObject\DocumentExpiry;
use Logistics\Fleet\Domain\ValueObject\DocumentOwnerType;
use Logistics\Fleet\Domain\ValueObject\FleetDocumentId;

function registrarDocumento(string $expiry = '2027-01-01'): FleetDocument
{
    return FleetDocument::register(
        FleetDocumentId::generate(),
        DocumentOwnerType::Vehicle,
        'veh-1',
        DocumentExpiry::of('soat', new DateTimeImmutable($expiry)),
        new DateTimeImmutable('2026-01-01'),
        'fleet-documents/soat.pdf',
        'Póliza anual',
    );
}

it('registra un documento de flota y graba el evento', function (): void {
    $document = registrarDocumento();

    expect($document->ownerType())->toBe(DocumentOwnerType::Vehicle)
        ->and($document->ownerId())->toBe('veh-1')
        ->and($document->filePath())->toBe('fleet-documents/soat.pdf');

    $events = $document->releaseEvents();
    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(DocumentRegistered::class);
});

it('reconoce un documento vencido y uno por vencer', function (): void {
    $vencido = registrarDocumento('2026-09-01');
    expect($vencido->isExpiredAt(new DateTimeImmutable('2026-10-13')))->toBeTrue();

    $porVencer = registrarDocumento('2026-10-20');
    expect($porVencer->expiresWithin(30, new DateTimeImmutable('2026-10-13')))->toBeTrue()
        ->and($porVencer->isExpiredAt(new DateTimeImmutable('2026-10-13')))->toBeFalse();
});

it('guarda la referencia (nº de póliza/certificado) y la expone en el evento', function (): void {
    $document = FleetDocument::register(
        FleetDocumentId::generate(),
        DocumentOwnerType::Vehicle,
        'veh-1',
        DocumentExpiry::of('soat', new DateTimeImmutable('2027-01-01')),
        new DateTimeImmutable('2026-01-01'),
        null,
        null,
        'POL-2026-00123',
    );

    expect($document->reference())->toBe('POL-2026-00123');

    $events = $document->releaseEvents();
    expect($events)->toHaveCount(1)
        ->and($events[0]->payload()['reference'])->toBe('POL-2026-00123');
});

it('acepta un documento sin referencia (null por defecto)', function (): void {
    $document = registrarDocumento();

    expect($document->reference())->toBeNull();
});
