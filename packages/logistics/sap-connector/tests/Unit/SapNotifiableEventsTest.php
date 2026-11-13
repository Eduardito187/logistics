<?php

declare(strict_types=1);

use Logistics\SapConnector\Application\Notification\SapNotifiableEvents;

/**
 * Catálogo de hechos avisables a SAP: pertenencia, exclusión de los de alto volumen y etiquetas. PHP puro.
 */
it('incluye en el catálogo los hechos de despacho clave', function (): void {
    expect(SapNotifiableEvents::CATALOG)
        ->toHaveKey('shipments.shipment_received')
        ->toHaveKey('execution.shipment_delivered')
        ->toHaveKey('execution.delivery_failed')
        ->toHaveKey('carriers.carrier_trip_started')
        ->toHaveKey('invoicing.invoice_issued');
});

it('excluye del catálogo el evento de GPS de alto volumen', function (): void {
    expect(SapNotifiableEvents::CATALOG)
        ->not->toHaveKey('telemetry.vehicle_position_reported');
});

it('los defaults son exactamente las claves del catálogo', function (): void {
    expect(SapNotifiableEvents::defaults())->toBe(array_keys(SapNotifiableEvents::CATALOG))
        ->and(SapNotifiableEvents::defaults())->toContain('execution.shipment_delivered')
        ->and(SapNotifiableEvents::defaults())->not->toContain('telemetry.vehicle_position_reported');
});

it('devuelve la etiqueta del catálogo para un evento conocido', function (): void {
    expect(SapNotifiableEvents::label('execution.shipment_delivered'))->toBe('Shipment delivered')
        ->and(SapNotifiableEvents::label('invoicing.invoice_issued'))->toBe('Invoice issued');
});

it('humaniza el nombre de un evento fuera del catálogo tomando el segmento final', function (): void {
    expect(SapNotifiableEvents::label('telemetry.vehicle_position_reported'))->toBe('Vehicle position reported');
});

it('humaniza un nombre sin punto tal cual', function (): void {
    expect(SapNotifiableEvents::label('standalone_event'))->toBe('Standalone event');
});
