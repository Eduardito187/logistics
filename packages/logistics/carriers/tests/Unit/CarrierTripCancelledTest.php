<?php

declare(strict_types=1);

use Logistics\Carriers\Domain\Event\CarrierTripCancelled;

/**
 * Evento de cancelación de un viaje programado: nombre estable y carga con los envíos liberados.
 */
it('expone el nombre del evento y la carga completa', function (): void {
    $event = new CarrierTripCancelled(
        '0190a0b0-0000-7000-8000-000000000010',
        '0190a0b0-0000-7000-8000-0000000000aa',
        '2026-11-13T08:00:00-04:00',
        'Truck broke down',
        ['0190a0b0-0000-7000-8000-000000000001', '0190a0b0-0000-7000-8000-000000000002'],
    );

    expect($event->eventName())->toBe('carriers.carrier_trip_cancelled')
        ->and($event->aggregateId())->toBe('0190a0b0-0000-7000-8000-000000000010')
        ->and($event->payload())->toBe([
            'carrier_id' => '0190a0b0-0000-7000-8000-0000000000aa',
            'cancelled_at' => '2026-11-13T08:00:00-04:00',
            'reason' => 'Truck broke down',
            'shipment_ids' => ['0190a0b0-0000-7000-8000-000000000001', '0190a0b0-0000-7000-8000-000000000002'],
        ]);
});

it('admite una cancelación sin motivo', function (): void {
    $event = new CarrierTripCancelled(
        '0190a0b0-0000-7000-8000-000000000010',
        '0190a0b0-0000-7000-8000-0000000000aa',
        '2026-11-13T08:00:00-04:00',
        null,
        [],
    );

    expect($event->payload()['reason'])->toBeNull()
        ->and($event->payload()['shipment_ids'])->toBe([]);
});
