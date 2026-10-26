<?php

declare(strict_types=1);

use Logistics\Shipments\Infrastructure\Persistence\Eloquent\PhysicalUnitRecord;
use Logistics\Shipments\Infrastructure\Persistence\Eloquent\ShipmentLineRecord;
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

it('expone el producto (sku, nombre, foto, pieza) de cada bulto del envío', function (): void {
    $shipment = ShipmentRecord::factory()->create();
    $line = ShipmentLineRecord::factory()->create([
        'shipment_id' => $shipment->id,
        'sku' => 'SKU-HELA',
        'product_name' => 'Heladera 400L',
        'product_image_url' => 'https://cdn.test/hela.png',
    ]);
    $unit = PhysicalUnitRecord::factory()->create([
        'shipment_id' => $shipment->id,
        'line_id' => $line->id,
        'unit_index' => 1,
        'unit_total' => 1,
        'piece_index' => 1,
        'piece_total' => 2,
    ]);
    UnitTrackingRecord::factory()->create([
        'shipment_id' => $shipment->id,
        'unit_id' => $unit->id,
        'status' => UnitStatus::Loaded->value,
    ]);

    $this->getJson("/api/v1/app/tracking/shipments/{$shipment->id}/units")
        ->assertOk()
        ->assertJsonPath('data.units.0.label', '1/1 (1/2)')
        ->assertJsonPath('data.units.0.sku', 'SKU-HELA')
        ->assertJsonPath('data.units.0.product_name', 'Heladera 400L')
        ->assertJsonPath('data.units.0.product_image_url', 'https://cdn.test/hela.png')
        ->assertJsonPath('data.units.0.piece_index', 1)
        ->assertJsonPath('data.units.0.piece_total', 2);
});

it('cae a producto legible si la unidad ya no existe en shipments', function (): void {
    $shipment = ShipmentRecord::factory()->create();
    UnitTrackingRecord::factory()->create([
        'shipment_id' => $shipment->id,
        'unit_id' => 'e9999999-9999-7999-8999-999999999999',
        'status' => UnitStatus::Loaded->value,
    ]);

    // Nombre y SKU SIEMPRE legibles (regla del dueño): sin producto resuelto, nombre "Producto" y SKU
    // "". La etiqueta de posición (label) y la pieza sí pueden faltar (null).
    $this->getJson("/api/v1/app/tracking/shipments/{$shipment->id}/units")
        ->assertOk()
        ->assertJsonPath('data.units.0.label', null)
        ->assertJsonPath('data.units.0.sku', '')
        ->assertJsonPath('data.units.0.product_name', 'Producto')
        ->assertJsonPath('data.units.0.piece_total', null);
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
        ->assertSee('Tus productos', false);
});

it('renderiza 404 en la página pública para un token inexistente', function (): void {
    $this->get('/t/'.TrackingToken::generate()->toString())
        ->assertNotFound()
        ->assertSee('no está disponible', false);
});
