<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Logistics\Shared\Domain\ValueObject\Uuid;

/**
 * Cobertura del ciclo de vida de Ejecución a través de los endpoints HTTP de la puerta app.
 *
 * Se omite el middleware de puerta (auth/device/idempotency) para ejercitar solo el contrato de los
 * controllers, sus FormRequest y la cadena CQRS detrás (comandos, consultas, ReadModels, repositorios y
 * mappers). No caza bugs: recorre el happy-path real de cada endpoint contra logistics_test.
 */
beforeEach(function (): void {
    $this->withoutMiddleware();
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

/**
 * Campos comunes de un escaneo de escritura (GPS, usuario/dispositivo, hora e idempotencia).
 *
 * @return array<string, mixed>
 */
function coverageScanBody(string $shipmentId, string $unitId = 'U-1'): array
{
    return [
        'shipment_id' => $shipmentId,
        'unit_id' => $unitId,
        'lat' => -16.5,
        'lng' => -68.15,
        'device_id' => 'device-01',
        'user_id' => 'user-07',
        'occurred_at' => '2026-10-13T09:00:00+00:00',
        'idempotency_key' => Uuid::generate()->value,
    ];
}

it('recorre el ciclo de una entrega entregada por sus endpoints', function (): void {
    $shipment = Uuid::generate()->value;
    $vehicle = Uuid::generate()->value;
    $node = Uuid::generate()->value;

    $this->postJson('/api/v1/app/deliveries/scans/preparation', coverageScanBody($shipment) + [
        'vehicle_id' => $vehicle,
    ])->assertOk();

    $this->postJson('/api/v1/app/deliveries/scans/load', coverageScanBody($shipment))->assertOk();

    $this->postJson('/api/v1/app/deliveries/scans/unload', coverageScanBody($shipment) + [
        'node_id' => $node,
    ])->assertOk();

    $this->postJson('/api/v1/app/deliveries/scans/node-reception', coverageScanBody($shipment) + [
        'node_id' => $node,
    ])->assertOk();

    $this->postJson('/api/v1/app/deliveries/proof-of-delivery', coverageScanBody($shipment) + [
        'confirmation' => 'QR-OK-1',
    ])->assertOk()
        ->assertJsonPath('data.status', 'DELIVERED');

    // Lectura: estado de la entrega y hoja de ruta del vehículo (consultas + ReadModels).
    $this->getJson("/api/v1/app/deliveries/{$shipment}")
        ->assertOk()
        ->assertJsonPath('data.shipment_id', $shipment);

    $this->getJson("/api/v1/app/vehicles/{$vehicle}/deliveries")
        ->assertOk()
        ->assertJsonPath('meta.count', 1);
});

it('recorre el ciclo de una entrega fallida por sus endpoints', function (): void {
    $shipment = Uuid::generate()->value;
    $vehicle = Uuid::generate()->value;

    $this->postJson('/api/v1/app/deliveries/scans/preparation', coverageScanBody($shipment) + [
        'vehicle_id' => $vehicle,
    ])->assertOk();
    $this->postJson('/api/v1/app/deliveries/scans/load', coverageScanBody($shipment))->assertOk();

    $this->postJson('/api/v1/app/deliveries/failed', coverageScanBody($shipment) + [
        'reason' => 'CUSTOMER_ABSENT',
    ])->assertOk()
        ->assertJsonPath('data.status', 'FAILED');
});

it('arma la ruta del dia, la relee y reporta un incidente por sus endpoints', function (): void {
    $vehicle = Uuid::generate()->value;
    $shipment = Uuid::generate()->value;

    $this->postJson("/api/v1/app/vehicles/{$vehicle}/route", [
        'route_date' => '2026-10-13',
        'stops' => [
            [
                'shipment_id' => $shipment,
                'sequence' => 1,
                'order_reference' => 'ORD-1',
                'customer_name' => 'Cliente Uno',
                'address' => 'Av. Siempre Viva 1',
                'eta_at' => '2026-10-13T09:00:00+00:00',
                'lat' => -16.5,
                'lng' => -68.15,
            ],
            [
                'shipment_id' => Uuid::generate()->value,
                'sequence' => 2,
            ],
        ],
    ])->assertCreated();

    // "Mi ruta" del chofer: sin fecha (ruta abierta vigente) y con fecha concreta.
    $this->getJson("/api/v1/app/vehicles/{$vehicle}/route")->assertOk();
    $this->getJson("/api/v1/app/vehicles/{$vehicle}/route?date=2026-10-13")->assertOk();

    // Incidente leve: la ruta sigue, solo empuja las ETAs de las paradas pendientes.
    $this->postJson("/api/v1/app/vehicles/{$vehicle}/incidents", [
        'severity' => 'MINOR',
        'type' => 'BREAKDOWN',
        'note' => 'Pinchazo leve',
        'lat' => -16.5,
        'lng' => -68.15,
        'device_id' => 'device-01',
        'user_id' => 'user-07',
        'occurred_at' => '2026-10-13T10:00:00+00:00',
        'idempotency_key' => Uuid::generate()->value,
    ])->assertStatus(202);
});

it('abre, recibe, concilia y relee una recepcion en nodo por sus endpoints', function (): void {
    $shipment = Uuid::generate()->value;
    $origin = Uuid::generate()->value;
    $destination = Uuid::generate()->value;

    $this->postJson('/api/v1/app/node-receptions', [
        'shipment_id' => $shipment,
        'origin_node_id' => $origin,
        'destination_node_id' => $destination,
        'origin_node_kind' => 'CD',
        'destination_node_kind' => 'STORE',
        'expected_unit_ids' => ['U-1', 'U-2'],
        'occurred_at' => '2026-10-13T08:00:00+00:00',
    ])->assertStatus(201); // abrir la recepción crea el agregado (201 Created)

    $this->postJson('/api/v1/app/node-receptions/units', coverageScanBody($shipment, 'U-1') + [
        'destination_node_id' => $destination,
    ])->assertOk();

    $this->getJson("/api/v1/app/node-receptions/{$shipment}/{$destination}")
        ->assertOk();

    $this->postJson('/api/v1/app/node-receptions/reconcile', [
        'shipment_id' => $shipment,
        'destination_node_id' => $destination,
        'device_id' => 'device-01',
        'user_id' => 'user-07',
        'occurred_at' => '2026-10-13T11:00:00+00:00',
    ])->assertOk();
});

it('sube una foto de evidencia por su endpoint', function (): void {
    Storage::fake('pod');

    $this->postJson('/api/v1/app/deliveries/photos', [
        'shipment_id' => Uuid::generate()->value,
        'photo' => UploadedFile::fake()->image('pod.jpg'),
        'kind' => 'pod',
    ])->assertCreated();
});

it('devuelve 404 en las lecturas sin datos', function (): void {
    $this->getJson('/api/v1/app/deliveries/'.Uuid::generate()->value)->assertNotFound();
    $this->getJson('/api/v1/app/vehicles/'.Uuid::generate()->value.'/route')->assertNotFound();
    $this->getJson('/api/v1/app/node-receptions/'.Uuid::generate()->value.'/'.Uuid::generate()->value)
        ->assertNotFound();
});
