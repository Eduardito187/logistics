<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Logistics\Shared\Domain\ValueObject\Uuid;

// Nombre del cliente del envío sembrado; se verifica en la hoja de ruta y en el estado por envío.
const ORDER_REF_PHOTO_TEST_CUSTOMER = 'Cliente Carga';

/**
 * Pantalla de carga de la app: las entregas (hoja de ruta del vehículo y estado por envío) traen la ORDEN
 * (order_reference, no el id externo con sufijo de multishipping) y el cliente, para no mostrar «Envío 1».
 * Subida de fotos: la misma Idempotency-Key devuelve SIEMPRE la referencia original y nunca guarda otra
 * foto (la cola offline reintenta). Contra PostgreSQL (base logistics_test).
 */
beforeEach(function (): void {
    $this->withoutMiddleware();
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

/**
 * Envío hermano de retiro (id externo con sufijo) con su entrega abierta en un vehículo.
 *
 * @return array{shipment: string, vehicle: string, order: string}
 */
function dorSeedDelivery(): array
{
    $shipment = Uuid::generate()->value;
    $vehicle = Uuid::generate()->value;
    $order = 'ORD-DOR-'.strtoupper(substr($shipment, -6));

    DB::table('shipments')->insert([
        'id' => $shipment,
        'source' => 'commerce',
        'external_id' => $order.'#n:'.Uuid::generate()->value,
        'order_reference' => $order,
        'customer_name' => ORDER_REF_PHOTO_TEST_CUSTOMER,
        'address_text' => 'Av. Banzer 100',
        'address_confidence' => 90,
        'address_confidence_level' => 'high',
        'status' => 'IN_EXECUTION',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('execution_deliveries')->insert([
        'id' => Uuid::generate()->value,
        'shipment_id' => $shipment,
        'vehicle_id' => $vehicle,
        'status' => 'PREPARED',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return ['shipment' => $shipment, 'vehicle' => $vehicle, 'order' => $order];
}

it('la hoja de ruta del vehículo trae la orden y el cliente de cada entrega', function (): void {
    $seed = dorSeedDelivery();

    $this->getJson("/api/v1/app/vehicles/{$seed['vehicle']}/deliveries")
        ->assertOk()
        ->assertJsonPath('data.0.shipment_id', $seed['shipment'])
        ->assertJsonPath('data.0.order_reference', $seed['order'])
        ->assertJsonPath('data.0.reference', $seed['order'])
        ->assertJsonPath('data.0.customer_name', ORDER_REF_PHOTO_TEST_CUSTOMER);
});

it('el estado de la entrega de un envío también trae la orden y el cliente', function (): void {
    $seed = dorSeedDelivery();

    $this->getJson("/api/v1/app/deliveries/{$seed['shipment']}")
        ->assertOk()
        ->assertJsonPath('data.order_reference', $seed['order'])
        ->assertJsonPath('data.reference', $seed['order'])
        ->assertJsonPath('data.customer_name', ORDER_REF_PHOTO_TEST_CUSTOMER);
});

it('la misma Idempotency-Key devuelve la referencia original y no guarda una foto duplicada', function (): void {
    Storage::fake('pod');
    $shipment = Uuid::generate()->value;
    $key = Uuid::generate()->value;

    $upload = fn (string $name) => $this->withHeader('Idempotency-Key', $key)
        ->post('/api/v1/app/deliveries/photos', [
            'shipment_id' => $shipment,
            'photo' => UploadedFile::fake()->image($name),
            'kind' => 'pod',
        ], ['Accept' => 'application/json']);

    $first = $upload('pod.jpg')->assertCreated()->json('data.path');
    $retry = $upload('pod-retry.jpg')->assertCreated()->json('data.path');

    expect($retry)->toBe($first)
        ->and(Storage::disk('pod')->files("pod/{$shipment}"))->toHaveCount(1);
});

it('acepta la clave también como campo del formulario y claves distintas guardan fotos distintas', function (): void {
    Storage::fake('pod');
    $shipment = Uuid::generate()->value;

    $upload = fn (string $key) => $this->post('/api/v1/app/deliveries/photos', [
        'shipment_id' => $shipment,
        'photo' => UploadedFile::fake()->image('pod.jpg'),
        'idempotency_key' => $key,
    ], ['Accept' => 'application/json'])->assertCreated()->json('data.path');

    $a = $upload('clave-a');
    $aAgain = $upload('clave-a');
    $b = $upload('clave-b');

    expect($aAgain)->toBe($a)
        ->and($b)->not->toBe($a)
        ->and(Storage::disk('pod')->files("pod/{$shipment}"))->toHaveCount(2);
});
