<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Logistics\Compensation\Domain\ValueObject\CompensationScope;
use Logistics\Compensation\Infrastructure\Read\DbAffectedValueReader;

/*
| Lector del valor afectado contra las proyecciones reales de shipments (logistics_test). Inserta envíos y
| líneas y verifica la base por alcance, el acumulado por orden y la caída a null (regla 6).
*/

beforeEach(function (): void {
    $this->reader = new DbAffectedValueReader(DB::connection());
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

/**
 * Inserta un envío mínimo y devuelve su id.
 */
function davrShipment(?string $orderReference = null): string
{
    $id = (string) Str::uuid();
    DB::table('shipments')->insert([
        'id' => $id,
        'source' => 'test',
        'external_id' => 'EXT-'.bin2hex(random_bytes(4)),
        'order_reference' => $orderReference,
        'customer_name' => 'Cliente de prueba',
        'address_text' => 'Av. Siempre Viva 123',
        'address_confidence' => 90,
        'address_confidence_level' => 'high',
        'status' => 'delivered',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $id;
}

function davrLine(string $shipmentId, string $sku, int $priceCents, int $qty = 1, ?string $currency = 'BOB'): void
{
    DB::table('shipment_lines')->insert([
        'id' => (string) Str::uuid(),
        'shipment_id' => $shipmentId,
        'sku' => $sku,
        'product_name' => 'Producto '.$sku,
        'quantity' => $qty,
        'unit_price' => $currency === null ? null : $priceCents / 100,
        'unit_price_currency' => $currency,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

it('suma las líneas del envío para el alcance SHIPMENT', function (): void {
    $shipment = davrShipment();
    davrLine($shipment, 'SKU-A', 20_000, 2);
    davrLine($shipment, 'SKU-B', 5_000, 1);

    $affected = $this->reader->affectedForShipment($shipment, CompensationScope::Shipment);

    expect($affected)->not->toBeNull()
        ->and($affected->base->cents)->toBe(45_000);
});

it('filtra por SKU en el alcance LINE', function (): void {
    $shipment = davrShipment();
    davrLine($shipment, 'SKU-A', 20_000, 1);
    davrLine($shipment, 'SKU-B', 5_000, 1);

    $affected = $this->reader->affectedForShipment($shipment, CompensationScope::Line, ['SKU-A']);

    expect($affected)->not->toBeNull()
        ->and($affected->base->cents)->toBe(20_000);
});

it('arma el orderBase con los envíos hermanos de la misma orden', function (): void {
    $orderRef = 'ORD-'.bin2hex(random_bytes(4));
    $main = davrShipment($orderRef);
    $sibling = davrShipment($orderRef);
    davrLine($main, 'SKU-A', 20_000, 1);
    davrLine($sibling, 'SKU-C', 30_000, 1);

    $affected = $this->reader->affectedForShipment($main, CompensationScope::Shipment);

    expect($affected)->not->toBeNull()
        ->and($affected->base->cents)->toBe(20_000)
        ->and($affected->orderBase->cents)->toBe(50_000);
});

it('suma todos los hermanos como base en el alcance ORDER', function (): void {
    $orderRef = 'ORD-'.bin2hex(random_bytes(4));
    $main = davrShipment($orderRef);
    $sibling = davrShipment($orderRef);
    davrLine($main, 'SKU-A', 20_000, 1);
    davrLine($sibling, 'SKU-C', 30_000, 1);

    $affected = $this->reader->affectedForShipment($main, CompensationScope::Order);

    expect($affected)->not->toBeNull()
        ->and($affected->base->cents)->toBe(50_000);
});

it('devuelve null si una línea no tiene precio (regla 6)', function (): void {
    $shipment = davrShipment();
    davrLine($shipment, 'SKU-A', 0, 1, null);

    expect($this->reader->affectedForShipment($shipment, CompensationScope::Shipment))->toBeNull();
});

it('devuelve null si una línea no es BOB', function (): void {
    $shipment = davrShipment();
    davrLine($shipment, 'SKU-A', 20_000, 1, 'USD');

    expect($this->reader->affectedForShipment($shipment, CompensationScope::Shipment))->toBeNull();
});

it('devuelve null si el envío no tiene líneas', function (): void {
    $shipment = davrShipment();

    expect($this->reader->affectedForShipment($shipment, CompensationScope::Shipment))->toBeNull();
});
