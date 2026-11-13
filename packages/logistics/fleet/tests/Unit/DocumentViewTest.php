<?php

declare(strict_types=1);

use Logistics\Fleet\Application\ReadModel\DocumentView;
use Logistics\Fleet\Domain\Model\FleetDocument;
use Logistics\Fleet\Domain\ValueObject\DocumentExpiry;
use Logistics\Fleet\Domain\ValueObject\DocumentOwnerType;
use Logistics\Fleet\Domain\ValueObject\FleetDocumentId;

/**
 * Proyección de lectura de un documento de flota (polimórfico): solo primitivos.
 */
it('proyecta un documento con todos sus datos', function (): void {
    $document = FleetDocument::register(
        FleetDocumentId::generate(),
        DocumentOwnerType::Vehicle,
        'veh-1',
        DocumentExpiry::of('soat', new DateTimeImmutable('2027-01-01')),
        new DateTimeImmutable('2026-01-01'),
        'fleet-documents/soat.pdf',
        'Póliza anual',
        'POL-123',
    );

    $data = DocumentView::fromDomain($document)->toArray();

    expect($data['id'])->toBe($document->id()->toString())
        ->and($data['owner_type'])->toBe('vehicle')
        ->and($data['owner_id'])->toBe('veh-1')
        ->and($data['document_type'])->toBe('soat')
        ->and($data['expires_on'])->toBe('2027-01-01')
        ->and($data['issued_on'])->toBe('2026-01-01')
        ->and($data['file_path'])->toBe('fleet-documents/soat.pdf')
        ->and($data['note'])->toBe('Póliza anual')
        ->and($data['reference'])->toBe('POL-123');
});

it('proyecta un documento mínimo con los opcionales nulos', function (): void {
    $document = FleetDocument::register(
        FleetDocumentId::generate(),
        DocumentOwnerType::Driver,
        'drv-9',
        DocumentExpiry::of('driver_license', new DateTimeImmutable('2028-12-31')),
    );

    $data = DocumentView::fromDomain($document)->toArray();

    expect($data['owner_type'])->toBe('driver')
        ->and($data['issued_on'])->toBeNull()
        ->and($data['file_path'])->toBeNull()
        ->and($data['note'])->toBeNull()
        ->and($data['reference'])->toBeNull();
});
