<?php

declare(strict_types=1);

use Logistics\Labels\Application\Contract\LabelEnricher;
use Logistics\Labels\Application\ReadModel\EmittedLabelView;
use Logistics\Network\Infrastructure\Persistence\Eloquent\NodeRecord;
use Logistics\Shipments\Infrastructure\Persistence\Eloquent\PhysicalUnitRecord;
use Logistics\Shipments\Infrastructure\Persistence\Eloquent\ShipmentLineRecord;
use Logistics\Shipments\Infrastructure\Persistence\Eloquent\ShipmentRecord;

/**
 * Enriquecimiento cross-context de etiquetas (producto/origen/destino humanos, cero uuid) en lectura:
 * resuelve el producto por SKU (líneas del envío), el nombre del nodo de origen por su código, y el
 * destino del envío de la orden (domicilio vs nodo). Tolerante: sin envío, degrada a null.
 */
function baseLabelView(string $order, string $sku, string $originCode): EmittedLabelView
{
    return new EmittedLabelView(
        orderNumber: $order,
        labelId: 'L-'.$sku,
        keyId: 1,
        sku: $sku,
        lineNumber: 1,
        qrContent: '{}',
        zpl: '',
        emittedAt: 1,
        revoked: false,
        revokedAt: null,
        revokedReason: null,
        originCode: $originCode,
    );
}

it('enriquece la etiqueta con producto, nombre de nodo de origen y destino a domicilio', function (): void {
    NodeRecord::factory()->create(['code' => 'CD-TEST-01', 'name' => 'CD de Prueba', 'type' => 'distribution_center']);
    $shipment = ShipmentRecord::factory()->create([
        'external_id' => 'ORD-ENR-1',
        'destination_node_id' => null,
        'customer_name' => 'Juan Cliente',
        'address_text' => 'Calle Falsa 123',
    ]);
    ShipmentLineRecord::factory()->create([
        'shipment_id' => $shipment->id,
        'sku' => 'SKU-ENR',
        'product_name' => 'Taladro Pro',
        'product_image_url' => 'https://img.test/taladro.png',
    ]);

    $out = app(LabelEnricher::class)->enrich('ORD-ENR-1', [baseLabelView('ORD-ENR-1', 'SKU-ENR', 'CD-TEST-01')]);
    $a = $out[0]->toArray();

    expect($a['product_name'])->toBe('Taladro Pro')
        ->and($a['image_url'])->toBe('https://img.test/taladro.png')
        ->and($a['origin'])->toBe(['code' => 'CD-TEST-01', 'name' => 'CD de Prueba'])
        ->and($a['destination']['type'])->toBe('home')
        ->and($a['destination']['name'])->toBe('Juan Cliente')
        ->and($a['destination']['address'])->toContain('Calle Falsa 123');
})->skip(fn (): bool => env('DB_CONNECTION') !== 'pgsql', 'requiere PostgreSQL');

it('resuelve el destino de nodo a tipo store/warehouse con su nombre, sin dirección', function (): void {
    $store = NodeRecord::factory()->create(['code' => 'T-TEST-01', 'name' => 'Tienda Prueba', 'type' => 'store']);
    ShipmentRecord::factory()->create([
        'external_id' => 'ORD-ENR-2',
        'destination_node_id' => $store->id,
    ]);

    $out = app(LabelEnricher::class)->enrich('ORD-ENR-2', [baseLabelView('ORD-ENR-2', 'SKU-X', '')]);
    $d = $out[0]->toArray()['destination'];

    expect($d['type'])->toBe('store')
        ->and($d['name'])->toBe('Tienda Prueba')
        ->and($d['address'])->toBeNull();
})->skip(fn (): bool => env('DB_CONNECTION') !== 'pgsql', 'requiere PostgreSQL');

it('resuelve el unit_id del bulto por correlación línea+unit+piece (puente offline-first)', function (): void {
    $shipment = ShipmentRecord::factory()->create(['external_id' => 'ORD-ENR-UNIT', 'destination_node_id' => null]);
    $line = ShipmentLineRecord::factory()->create(['shipment_id' => $shipment->id, 'sku' => 'SKU-U', 'product_name' => 'Heladera']);
    $unit = PhysicalUnitRecord::factory()->create([
        'shipment_id' => $shipment->id,
        'line_id' => $line->id,
        'unit_index' => 1,
        'unit_total' => 1,
        'piece_index' => 1,
        'piece_total' => 1,
    ]);

    // Etiqueta de la 1ª línea (sku coincide), unidad 1, pieza 1 → debe atar a ese bulto.
    $view = baseLabelView('ORD-ENR-UNIT', 'SKU-U', 'CD-X');
    $out = app(LabelEnricher::class)->enrich('ORD-ENR-UNIT', [$view]);
    $a = $out[0]->toArray();

    expect($a['unit_id'])->toBe((string) $unit->id)
        ->and($a['shipment_id'])->toBe((string) $shipment->id)
        ->and($a['unit'])->toBe(['index' => 1, 'total' => 1])
        ->and($a['piece'])->toBe(['index' => 1, 'total' => 1]);
})->skip(fn (): bool => env('DB_CONNECTION') !== 'pgsql', 'requiere PostgreSQL');

it('degrada a null cuando no hay envío para la orden (tolerante)', function (): void {
    $out = app(LabelEnricher::class)->enrich('ORD-SIN-ENVIO', [baseLabelView('ORD-SIN-ENVIO', 'SKU-Z', 'CODIGO-RARO')]);
    $a = $out[0]->toArray();

    expect($a['destination'])->toBe(['type' => null, 'name' => null, 'address' => null])
        ->and($a['origin'])->toBe(['code' => 'CODIGO-RARO', 'name' => null]);
})->skip(fn (): bool => env('DB_CONNECTION') !== 'pgsql', 'requiere PostgreSQL');
