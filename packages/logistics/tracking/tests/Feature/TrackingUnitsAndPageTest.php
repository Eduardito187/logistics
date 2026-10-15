<?php

declare(strict_types=1);

use Logistics\Shipments\Infrastructure\Persistence\Eloquent\PhysicalUnitRecord;
use Logistics\Shipments\Infrastructure\Persistence\Eloquent\ShipmentRecord;
use Logistics\Tracking\Domain\ValueObject\TrackingToken;
use Logistics\Tracking\Domain\ValueObject\UnitStatus;
use Logistics\Tracking\Infrastructure\Persistence\Eloquent\TrackingRecord;
use Logistics\Tracking\Infrastructure\Persistence\Eloquent\UnitTrackingRecord;

/**
 * Contrato HTTP del detalle per-bulto y de la página pública de rastreo.
 *
 * Se omite el middleware de puerta (auth/device/canal) para ejercitar solo el contrato del payload y
 * del render. Corre contra logistics_test (RefreshDatabase).
 */
beforeEach(function (): void {
    $this->withoutMiddleware();
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

$seedUnits = function (string $shipmentId): void {
    // Dos bultos del envío con etiqueta "N de M" real y estado proyectado.
    $u1 = PhysicalUnitRecord::factory()->create([
        'shipment_id' => $shipmentId,
        'unit_index' => 1,
        'unit_total' => 2,
        'piece_index' => 1,
        'piece_total' => 1,
    ]);
    $u2 = PhysicalUnitRecord::factory()->create([
        'shipment_id' => $shipmentId,
        'unit_index' => 2,
        'unit_total' => 2,
        'piece_index' => 1,
        'piece_total' => 1,
    ]);

    UnitTrackingRecord::factory()->create([
        'shipment_id' => $shipmentId,
        'unit_id' => $u1->id,
        'status' => UnitStatus::Loaded->value,
    ]);
    UnitTrackingRecord::factory()->create([
        'shipment_id' => $shipmentId,
        'unit_id' => $u2->id,
        'status' => UnitStatus::Delivered->value,
    ]);
};

it('expone el detalle per-bulto interno por id de envío', function () use ($seedUnits): void {
    $shipment = ShipmentRecord::factory()->create();
    TrackingRecord::factory()->create([
        'shipment_id' => $shipment->id,
        'eta' => null,
        'last_lat' => null,
        'last_lng' => null,
        'last_position_at' => null,
    ]);
    $seedUnits($shipment->id);

    $response = $this->getJson("/api/v1/app/tracking/shipments/{$shipment->id}/units")
        ->assertOk()
        ->assertJsonPath('data.shipment_id', $shipment->id)
        ->assertJsonCount(2, 'data.units');

    $labels = array_column($response->json('data.units'), 'label');
    expect($labels)->toContain('1/2')->toContain('2/2');
});

it('expone el detalle público de bultos por token sin PII', function () use ($seedUnits): void {
    $shipment = ShipmentRecord::factory()->create();
    $token = TrackingToken::generate()->toString();
    TrackingRecord::factory()->create([
        'shipment_id' => $shipment->id,
        'token' => $token,
        'eta' => null,
        'last_lat' => null,
        'last_lng' => null,
        'last_position_at' => null,
    ]);
    $seedUnits($shipment->id);

    $response = $this->getJson("/api/v1/channels/tracking/{$token}/units")
        ->assertOk()
        ->assertJsonCount(2, 'data.units');

    // Sin PII ni claves internas: no aparece el id de envío ni el unit_id.
    $flat = json_encode($response->json('data'));
    expect($flat)->not->toContain($shipment->id);

    $messages = array_column($response->json('data.units'), 'status_message');
    expect($messages)->toContain('Cargado en el camión.')->toContain('Entregado.');
});

it('devuelve 404 público para un token inexistente', function (): void {
    $this->getJson('/api/v1/channels/tracking/'.TrackingToken::generate()->toString().'/units')
        ->assertNotFound()
        ->assertJsonPath('error.code', 'tracking_not_found');
});

it('renderiza la página pública de rastreo con el estado y el mensaje', function () use ($seedUnits): void {
    $shipment = ShipmentRecord::factory()->create();
    $token = TrackingToken::generate()->toString();
    TrackingRecord::factory()->create([
        'shipment_id' => $shipment->id,
        'token' => $token,
        'status' => 'ON_THE_WAY',
        'route_position' => 3,
        'remaining_stops' => 2,
        'eta' => null,
        'last_lat' => null,
        'last_lng' => null,
        'last_position_at' => null,
    ]);
    $seedUnits($shipment->id);

    $this->get("/t/{$token}")
        ->assertOk()
        ->assertSee('Tu pedido está a 2 paradas.')
        ->assertSee('Tus bultos', false);
});

it('renderiza 404 en la página pública para un token inexistente', function (): void {
    $this->get('/t/'.TrackingToken::generate()->toString())
        ->assertNotFound()
        ->assertSee('no está disponible', false);
});
