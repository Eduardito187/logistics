<?php

declare(strict_types=1);

use Logistics\Execution\Application\Command\OpenNodeReception\OpenNodeReception;
use Logistics\Execution\Application\Command\ReceiveUnitAtNode\ReceiveUnitAtNode;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shipments\Infrastructure\Persistence\Eloquent\PhysicalUnitRecord;
use Logistics\Shipments\Infrastructure\Persistence\Eloquent\ShipmentLineRecord;
use Logistics\Shipments\Infrastructure\Persistence\Eloquent\ShipmentRecord;

/**
 * La recepción en nodo expone `units`: el producto (foto, nombre, SKU, N de M) de cada bulto esperado,
 * recibido o sobrante, resuelto en lote desde shipment_units → shipment_lines. Un id que no es un bulto
 * conocido (p. ej. id de etiqueta) sale igual, con nulls.
 */
beforeEach(function (): void {
    $this->withoutMiddleware();
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

it('expone el producto de cada bulto en el GET de la recepción', function (): void {
    $shipment = ShipmentRecord::factory()->create();
    $line = ShipmentLineRecord::factory()->create([
        'shipment_id' => $shipment->id,
        'sku' => 'SKU-TV55',
        'product_name' => 'Televisor 55"',
        'product_image_url' => 'https://cdn.test/tv55.png',
    ]);
    $unit = PhysicalUnitRecord::factory()->create([
        'shipment_id' => $shipment->id,
        'line_id' => $line->id,
        'unit_index' => 1,
        'unit_total' => 1,
        'piece_index' => 2,
        'piece_total' => 2,
    ]);
    $origin = 'a1111111-1111-7111-8111-111111111111';
    $destination = 'b2222222-2222-7222-8222-222222222222';

    $bus = app(CommandBus::class);
    $bus->dispatch(new OpenNodeReception(
        shipmentId: $shipment->id,
        originNodeId: $origin,
        destinationNodeId: $destination,
        originNodeKind: 'CD',
        destinationNodeKind: 'STORE',
        expectedUnitIds: [$unit->id],
        occurredAt: '2026-10-13T08:00:00+00:00',
    ));
    // Sobrante con un id que no es bulto (id de etiqueta): debe salir con nulls.
    $bus->dispatch(new ReceiveUnitAtNode(
        shipmentId: $shipment->id,
        destinationNodeId: $destination,
        unitId: 'LBL-967df6',
        lat: -16.5,
        lng: -68.15,
        deviceId: 'device-01',
        userId: 'user-07',
        occurredAt: '2026-10-13T09:00:00+00:00',
        idempotencyKey: 'k-unknown-1',
    ));

    $response = $this->getJson("/api/v1/app/node-receptions/{$shipment->id}/{$destination}")
        ->assertOk()
        ->assertJsonCount(2, 'data.units');

    $units = collect($response->json('data.units'))->keyBy('unit_id');

    expect($units[$unit->id])->toBe([
        'unit_id' => $unit->id,
        'sku' => 'SKU-TV55',
        'product_name' => 'Televisor 55"',
        'product_image_url' => 'https://cdn.test/tv55.png',
        'unit_index' => 1,
        'unit_total' => 1,
        'piece_index' => 2,
        'piece_total' => 2,
    ])->and($units['LBL-967df6'])->toBe([
        // Id que no es una unidad conocida: nombre y SKU SIEMPRE legibles (fallback "Producto"/""),
        // nunca null; la foto y los índices sí pueden faltar.
        'unit_id' => 'LBL-967df6',
        'sku' => '',
        'product_name' => 'Producto',
        'product_image_url' => null,
        'unit_index' => null,
        'unit_total' => null,
        'piece_index' => null,
        'piece_total' => null,
    ]);
});

it('cae a producto legible cuando el id esperado no es un uuid de unidad', function (): void {
    $shipment = 'd3333333-3333-7333-8333-333333333333';
    $destination = 'b4444444-4444-7444-8444-444444444444';

    app(CommandBus::class)->dispatch(new OpenNodeReception(
        shipmentId: $shipment,
        originNodeId: 'a5555555-5555-7555-8555-555555555555',
        destinationNodeId: $destination,
        originNodeKind: 'CD',
        destinationNodeKind: 'STORE',
        expectedUnitIds: ['U-1'],
        occurredAt: '2026-10-13T08:00:00+00:00',
    ));

    $this->getJson("/api/v1/app/node-receptions/{$shipment}/{$destination}")
        ->assertOk()
        ->assertJsonPath('data.units.0.unit_id', 'U-1')
        ->assertJsonPath('data.units.0.sku', '')
        ->assertJsonPath('data.units.0.product_name', 'Producto');
});
