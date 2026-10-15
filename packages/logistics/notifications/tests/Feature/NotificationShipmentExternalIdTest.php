<?php

declare(strict_types=1);

use Logistics\Notifications\Infrastructure\Persistence\Eloquent\NotificationRecord;
use Logistics\Shipments\Infrastructure\Persistence\Eloquent\ShipmentRecord;

/**
 * Tests de contrato HTTP de notificaciones: las respuestas deben traer shipment_external_id
 * (referencia humana del envío, shipments.external_id) junto al UUID crudo shipment_id.
 *
 * Se omite el middleware de puerta (auth/device) para ejercitar solo el contrato del payload.
 * Corre contra logistics_test (RefreshDatabase).
 */
beforeEach(function (): void {
    $this->withoutMiddleware();
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

it('expone shipment_external_id en el detalle de una notificación', function (): void {
    $shipment = ShipmentRecord::factory()->create(['external_id' => 'SO-770001']);

    $notification = NotificationRecord::factory()->create(['shipment_id' => $shipment->id]);

    $this->getJson("/api/v1/app/notifications/{$notification->id}")
        ->assertOk()
        ->assertJsonPath('data.shipment_id', $shipment->id)
        ->assertJsonPath('data.shipment_external_id', 'SO-770001');
});

it('expone shipment_external_id en el log de notificaciones de un envío', function (): void {
    $shipment = ShipmentRecord::factory()->create(['external_id' => 'SO-770002']);

    NotificationRecord::factory()->create(['shipment_id' => $shipment->id]);

    $this->getJson("/api/v1/app/notifications?shipment_id={$shipment->id}")
        ->assertOk()
        ->assertJsonPath('data.0.shipment_id', $shipment->id)
        ->assertJsonPath('data.0.shipment_external_id', 'SO-770002');
});

it('expone shipment_external_id en el log global, resolviendo en lote', function (): void {
    $shipment = ShipmentRecord::factory()->create(['external_id' => 'SO-770003']);

    NotificationRecord::factory()->create(['shipment_id' => $shipment->id]);

    $this->getJson('/api/v1/app/notifications/all')
        ->assertOk()
        ->assertJsonPath('data.0.shipment_external_id', 'SO-770003');
});

it('deja shipment_external_id null cuando no hay envío asociado', function (): void {
    $shipmentId = '00000000-0000-7000-8000-0000000000aa';

    $notification = NotificationRecord::factory()->create(['shipment_id' => $shipmentId]);

    $this->getJson("/api/v1/app/notifications/{$notification->id}")
        ->assertOk()
        ->assertJsonPath('data.shipment_id', $shipmentId)
        ->assertJsonPath('data.shipment_external_id', null);
});
