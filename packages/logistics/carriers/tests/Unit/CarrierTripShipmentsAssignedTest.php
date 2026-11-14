<?php

declare(strict_types=1);

use Logistics\Carriers\Domain\Event\CarrierTripShipmentsAssigned;

/**
 * Evento de alta de envíos en un viaje programado: nombre estable y carga con los ids agregados.
 */
it('expone el nombre del evento y la carga', function (): void {
    $event = new CarrierTripShipmentsAssigned(
        '0190a0b0-0000-7000-8000-000000000010',
        '0190a0b0-0000-7000-8000-0000000000aa',
        ['0190a0b0-0000-7000-8000-000000000001', '0190a0b0-0000-7000-8000-000000000002'],
    );

    expect($event->eventName())->toBe('carriers.carrier_trip_shipments_assigned')
        ->and($event->aggregateId())->toBe('0190a0b0-0000-7000-8000-000000000010')
        ->and($event->payload())->toBe([
            'carrier_id' => '0190a0b0-0000-7000-8000-0000000000aa',
            'shipment_ids' => ['0190a0b0-0000-7000-8000-000000000001', '0190a0b0-0000-7000-8000-000000000002'],
        ]);
});
