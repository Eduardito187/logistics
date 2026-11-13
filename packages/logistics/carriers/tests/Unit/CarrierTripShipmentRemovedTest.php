<?php

declare(strict_types=1);

use Logistics\Carriers\Domain\Event\CarrierTripShipmentRemoved;

/**
 * Evento de quita de un envío de un viaje programado: nombre estable y carga mínima.
 */
it('expone el nombre del evento y la carga', function (): void {
    $event = new CarrierTripShipmentRemoved(
        '0190a0b0-0000-7000-8000-000000000010',
        '0190a0b0-0000-7000-8000-0000000000aa',
        '0190a0b0-0000-7000-8000-000000000001',
    );

    expect($event->eventName())->toBe('carriers.carrier_trip_shipment_removed')
        ->and($event->aggregateId())->toBe('0190a0b0-0000-7000-8000-000000000010')
        ->and($event->payload())->toBe([
            'carrier_id' => '0190a0b0-0000-7000-8000-0000000000aa',
            'shipment_id' => '0190a0b0-0000-7000-8000-000000000001',
        ]);
});
