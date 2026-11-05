<?php

declare(strict_types=1);

use Logistics\Labels\Application\Query\GetDispatchLines\GetDispatchLines;
use Logistics\Network\Infrastructure\Persistence\Eloquent\NodeRecord;
use Logistics\Planning\Infrastructure\Persistence\Eloquent\PlannedStopRecord;
use Logistics\Planning\Infrastructure\Persistence\Eloquent\PlanRecord;
use Logistics\Shared\Application\Query\QueryBus;
use Logistics\Shipments\Infrastructure\Persistence\Eloquent\ShipmentLineRecord;
use Logistics\Shipments\Infrastructure\Persistence\Eloquent\ShipmentRecord;

/**
 * Líneas de despacho de una orden para la pantalla de etiquetado por producto: producto por SKU, destino
 * humano del envío, autorización por plan publicado, estado de etiqueta y envelope con resumen de grupos.
 */
function dispatchLines(string $orderNumber): array
{
    return app(QueryBus::class)->ask(new GetDispatchLines($orderNumber))->toArray();
}

it('arma el envelope con producto, destino a domicilio, pending y no autorizado sin plan', function (): void {
    $shipment = ShipmentRecord::factory()->create([
        'external_id' => 'ORD-DL-1',
        'destination_node_id' => null,
        'customer_name' => 'Ana Cliente',
        'address_text' => 'Av. Siempre Viva 742',
    ]);
    ShipmentLineRecord::factory()->create(['shipment_id' => $shipment->id, 'sku' => 'SKU-A', 'product_name' => 'Taladro', 'product_image_url' => 'https://img.test/a.png', 'quantity' => 2]);
    ShipmentLineRecord::factory()->create(['shipment_id' => $shipment->id, 'sku' => 'SKU-B', 'product_name' => 'Brocas', 'product_image_url' => null, 'quantity' => 1]);

    $res = dispatchLines('ORD-DL-1');

    expect(array_keys($res))->toBe(['order_reference', 'destinations', 'data'])
        ->and($res['order_reference'])->toBe('ORD-DL-1')
        ->and($res['destinations'])->toHaveCount(1)
        ->and($res['destinations'][0]['line_count'])->toBe(2)
        ->and($res['destinations'][0]['destination']['type'])->toBe('home')
        ->and($res['data'])->toHaveCount(2);

    $line = $res['data'][0];
    expect($line['sku'])->toBe('SKU-A')
        ->and($line['product_name'])->toBe('Taladro')
        ->and($line['image_url'])->toBe('https://img.test/a.png')
        ->and($line['quantity'])->toBe(2)
        ->and($line['pieces_per_unit'])->toBe(1)
        ->and($line['line_number'])->toBe(1)
        ->and($line['destination']['type'])->toBe('home')
        ->and($line['destination']['name'])->toBe('Ana Cliente')
        ->and($line['shipment_reference'])->toBe('ORD-DL-1')
        ->and($line['authorized'])->toBeFalse()
        ->and($line['authorized_reason'])->toBe('sin plan publicado')
        ->and($line['label_status'])->toBe('pending')
        ->and($line['origin'])->toBe(['code' => '', 'name' => null]);
})->skip(fn (): bool => env('DB_CONNECTION') !== 'pgsql', 'requiere PostgreSQL');

it('marca autorizado cuando el envío está en un plan publicado y resuelve destino de nodo', function (): void {
    $store = NodeRecord::factory()->create(['code' => 'T-DL-01', 'name' => 'Tienda DL', 'type' => 'store']);
    $shipment = ShipmentRecord::factory()->create(['external_id' => 'ORD-DL-2', 'destination_node_id' => $store->id]);
    ShipmentLineRecord::factory()->create(['shipment_id' => $shipment->id, 'sku' => 'SKU-C', 'product_name' => 'Martillo', 'quantity' => 1]);

    $plan = PlanRecord::factory()->published()->create();
    PlannedStopRecord::factory()->create(['plan_id' => $plan->id, 'shipment_id' => $shipment->id]);

    $res = dispatchLines('ORD-DL-2');
    $line = $res['data'][0];

    expect($line['authorized'])->toBeTrue()
        ->and($line['authorized_reason'])->toBeNull()
        ->and($line['destination']['type'])->toBe('store')
        ->and($line['destination']['name'])->toBe('Tienda DL')
        ->and($line['destination']['address'])->toBeNull();
})->skip(fn (): bool => env('DB_CONNECTION') !== 'pgsql', 'requiere PostgreSQL');

it('devuelve envelope vacío cuando la orden no tiene envío', function (): void {
    $res = dispatchLines('ORD-DL-INEXISTENTE');

    expect($res['order_reference'])->toBe('ORD-DL-INEXISTENTE')
        ->and($res['destinations'])->toBe([])
        ->and($res['data'])->toBe([]);
})->skip(fn (): bool => env('DB_CONNECTION') !== 'pgsql', 'requiere PostgreSQL');
