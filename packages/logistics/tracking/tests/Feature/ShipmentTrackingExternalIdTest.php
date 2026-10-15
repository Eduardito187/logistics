<?php

declare(strict_types=1);

use Logistics\Shipments\Infrastructure\Persistence\Eloquent\ShipmentRecord;
use Logistics\Tracking\Infrastructure\Persistence\Eloquent\TrackingRecord;

/**
 * Test de contrato HTTP del seguimiento interno: la respuesta debe traer shipment_external_id
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

// Los dos primeros tests aíslan la verificación de shipment_external_id sembrando el seguimiento sin
// campos temporales. El tercer test cubre explícitamente el caso con eta y posición (ver regresión
// más abajo), que antes reventaba en TrackingMapper::toDomain.
$withoutTemporalFields = [
    'eta' => null,
    'last_lat' => null,
    'last_lng' => null,
    'last_position_at' => null,
];

it('expone shipment_external_id resuelto desde el envío asociado', function () use ($withoutTemporalFields): void {
    $shipment = ShipmentRecord::factory()->create(['external_id' => 'SO-900123']);

    $tracking = TrackingRecord::factory()->create([
        'shipment_id' => $shipment->id,
        ...$withoutTemporalFields,
    ]);

    $this->getJson("/api/v1/app/tracking/shipments/{$shipment->id}")
        ->assertOk()
        ->assertJsonPath('data.shipment_id', $shipment->id)
        ->assertJsonPath('data.shipment_external_id', 'SO-900123')
        ->assertJsonPath('data.id', $tracking->id);
});

it('deja shipment_external_id null cuando no hay envío asociado', function () use ($withoutTemporalFields): void {
    $shipmentId = '00000000-0000-7000-8000-000000000001';

    TrackingRecord::factory()->create([
        'shipment_id' => $shipmentId,
        ...$withoutTemporalFields,
    ]);

    $this->getJson("/api/v1/app/tracking/shipments/{$shipmentId}")
        ->assertOk()
        ->assertJsonPath('data.shipment_id', $shipmentId)
        ->assertJsonPath('data.shipment_external_id', null);
});

// Regresión del bug de TrackingMapper::toDomain: eta/last_position_at están casteados a Carbon en el
// Record; el mapper los pasaba a new DateTimeImmutable(string) y reventaba con 500 cuando venían con
// valor. Con el fix (toDateTimeImmutable) el endpoint responde 200 y expone eta y last_position.
it('reconstruye el seguimiento con eta y posición sin reventar (regresión)', function (): void {
    $shipment = ShipmentRecord::factory()->create(['external_id' => 'SO-900555']);

    TrackingRecord::factory()->create([
        'shipment_id' => $shipment->id,
        'eta' => '2026-10-13T12:00:00+00:00',
        'last_lat' => '-17.7800000',
        'last_lng' => '-63.1800000',
        'last_position_at' => '2026-10-13T11:30:00+00:00',
    ]);

    $response = $this->getJson("/api/v1/app/tracking/shipments/{$shipment->id}")
        ->assertOk()
        ->assertJsonPath('data.shipment_external_id', 'SO-900555');

    expect($response->json('data.eta'))->not->toBeNull();
    expect($response->json('data.last_position.lat'))->toBe(-17.78);
    expect($response->json('data.last_position.lng'))->toBe(-63.18);
});
