<?php

declare(strict_types=1);

use Logistics\Fleet\Domain\Event\FleetDocumentsExpiryAlerted;

it('expone el nombre y la carga del evento de alerta de vencimientos', function (): void {
    $documents = [
        ['owner_type' => 'vehicle', 'owner_id' => 'veh-1', 'owner_label' => 'ABC-123', 'document_type' => 'soat', 'expires_on' => '2026-10-01', 'expired' => true],
        ['owner_type' => 'driver', 'owner_id' => 'drv-1', 'owner_label' => 'Juan', 'document_type' => 'license', 'expires_on' => '2026-11-01', 'expired' => false],
    ];

    $event = new FleetDocumentsExpiryAlerted(
        'fleet-document-expiry-2026-10-13',
        '2026-10-13',
        30,
        1,
        1,
        $documents,
    );

    expect($event->eventName())->toBe('fleet.documents_expiry_alerted')
        ->and($event->aggregateId())->toBe('fleet-document-expiry-2026-10-13');

    $payload = $event->payload();

    expect($payload['reference_date'])->toBe('2026-10-13')
        ->and($payload['threshold_days'])->toBe(30)
        ->and($payload['expired_count'])->toBe(1)
        ->and($payload['expiring_count'])->toBe(1)
        ->and($payload['total_count'])->toBe(2)
        ->and($payload['documents'])->toBe($documents);
});
