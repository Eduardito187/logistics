<?php

declare(strict_types=1);

use Commerce\Orders\Infrastructure\Persistence\Eloquent\Model\OrderRecord;
use Illuminate\Support\Facades\Artisan;
use Logistics\Shipments\Domain\ValueObject\ShipmentStatus;
use Logistics\Shipments\Infrastructure\Persistence\Eloquent\ShipmentLineRecord;
use Logistics\Shipments\Infrastructure\Persistence\Eloquent\ShipmentRecord;

/**
 * Backfill de órdenes de commerce a partir de los envíos existentes (pedidos que entraron como `shipments`
 * y nunca pasaron por el checkout). Contra PostgreSQL real (logistics_test, RefreshDatabase): verifica el
 * agrupado por `order_reference` (multishipping = una orden), el fallback por envío suelto, la simulación
 * sin `--force` y la idempotencia.
 */
beforeEach(function (): void {
    // Nada que preparar: cada test siembra sus propios envíos.
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

it('sin --force solo simula: no escribe ninguna orden', function (): void {
    $reference = 'ORD-MULTI-'.uniqid();
    $shipment = ShipmentRecord::factory()->create([
        'order_reference' => $reference,
        'status' => ShipmentStatus::Received->value,
    ]);
    ShipmentLineRecord::factory()->create(['shipment_id' => $shipment->id]);

    Artisan::call('commerce:orders:backfill-from-shipments');

    expect(OrderRecord::query()->count())->toBe(0);
});

it('con --force crea una orden por pedido (multishipping = 1) y es idempotente', function (): void {
    $reference = 'ORD-MULTI-'.uniqid();

    // Multishipping: dos envíos que comparten order_reference = UN solo pedido.
    $first = ShipmentRecord::factory()->create([
        'order_reference' => $reference,
        'status' => ShipmentStatus::Received->value,
    ]);
    $second = ShipmentRecord::factory()->create([
        'order_reference' => $reference,
        'status' => ShipmentStatus::Received->value,
    ]);
    ShipmentLineRecord::factory()->create(['shipment_id' => $first->id]);
    ShipmentLineRecord::factory()->create(['shipment_id' => $second->id]);

    // Envío suelto SIN referencia = su propio pedido; PLANNED ⇒ la orden queda en fulfillment.
    $solo = ShipmentRecord::factory()->create([
        'order_reference' => null,
        'status' => ShipmentStatus::Planned->value,
    ]);
    ShipmentLineRecord::factory()->create(['shipment_id' => $solo->id]);

    Artisan::call('commerce:orders:backfill-from-shipments', ['--force' => true]);

    // Dos pedidos ⇒ dos órdenes (el grupo multishipping no se duplica).
    expect(OrderRecord::query()->count())->toBe(2);

    // El grupo multishipping produjo UNA orden, con las dos líneas y marcada como backfill.
    $multi = OrderRecord::query()->where('order_reference', $reference)->first();
    expect($multi)->not->toBeNull()
        ->and($multi->source)->toBe('backfill')
        ->and($multi->status)->toBe('paid')
        ->and($multi->lines)->toHaveCount(2);

    // El envío suelto (PLANNED) produjo una orden en fulfillment, bajo su external_id.
    $single = OrderRecord::query()->where('order_reference', $solo->external_id)->first();
    expect($single)->not->toBeNull()
        ->and($single->source)->toBe('backfill')
        ->and($single->status)->toBe('fulfilling');

    // Idempotente: una segunda corrida no crea órdenes nuevas.
    Artisan::call('commerce:orders:backfill-from-shipments', ['--force' => true]);
    expect(OrderRecord::query()->count())->toBe(2);
});
