<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Http\Middleware\EnsureDeviceToken;
use App\Http\Middleware\IdempotentRequests;
use App\Models\User;
use Logistics\Execution\Application\Command\RecordLoadScan\RecordLoadScan;
use Logistics\Execution\Application\Command\RecordPreparationScan\RecordPreparationScan;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Domain\ValueObject\Uuid;
use Logistics\Shipments\Infrastructure\Persistence\Eloquent\ShipmentRecord;

/**
 * Alcance por tienda de la prueba de entrega (POD) para el rol Tienda.
 *
 * El POD es por shipmentId (no manda nodeId), así que el middleware enforce.store.node no aplica: el
 * chequeo lo hace el controller leyendo el nodo destino del envío. Una tienda solo puede registrar el POD
 * de un envío de SU nodo; sobre otro nodo (o un envío sin nodo) recibe 403 RFC 7807. Contra PostgreSQL.
 */
beforeEach(function (): void {
    $this->withoutMiddleware([EnsureDeviceToken::class, IdempotentRequests::class]);
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

/**
 * Crea un usuario Tienda autenticado por Sanctum con el nodo asignado indicado.
 */
function podScopeActingAsStore(?string $nodeId): User
{
    $user = User::factory()->create();
    $user->assignRole(Role::Tienda);
    $user->forceFill(['node_id' => $nodeId])->save();
    test()->actingAs($user, 'sanctum');

    return $user;
}

/**
 * Prepara y carga un bulto para dejar la entrega lista para el POD.
 */
function podScopeOpenDelivery(string $shipment, string $vehicle, string $unit): void
{
    /** @var CommandBus $bus */
    $bus = app(CommandBus::class);

    $bus->dispatch(new RecordPreparationScan(
        shipmentId: $shipment,
        vehicleId: $vehicle,
        unitId: $unit,
        lat: -16.5,
        lng: -68.15,
        deviceId: 'device-01',
        userId: 'user-07',
        occurredAt: '2026-10-16T08:00:00+00:00',
        idempotencyKey: 'prep-'.$unit,
    ));

    $bus->dispatch(new RecordLoadScan(
        shipmentId: $shipment,
        unitId: $unit,
        lat: -16.5,
        lng: -68.15,
        deviceId: 'device-01',
        userId: 'user-07',
        occurredAt: '2026-10-16T08:30:00+00:00',
        idempotencyKey: 'load-'.$unit,
    ));
}

/**
 * Body válido del POD para el envío/bulto dados.
 *
 * @return array<string, mixed>
 */
function podScopeBody(string $shipment, string $unit): array
{
    return [
        'shipment_id' => $shipment,
        'unit_id' => $unit,
        'confirmation' => 'QR-OK',
        'lat' => -16.5,
        'lng' => -68.15,
        'occurred_at' => '2026-10-16T11:00:00+00:00',
        'device_id' => 'device-01',
        'user_id' => 'user-07',
        'idempotency_key' => 'pod-'.$unit,
    ];
}

it('permite a una tienda registrar el POD de un envío de su propio nodo', function (): void {
    $node = Uuid::generate()->value;
    $shipment = Uuid::generate()->value;
    $vehicle = Uuid::generate()->value;
    $unit = 'U-1';

    ShipmentRecord::factory()->forNode($node)->create(['id' => $shipment]);
    podScopeOpenDelivery($shipment, $vehicle, $unit);
    podScopeActingAsStore($node);

    test()->postJson('/api/v1/app/deliveries/proof-of-delivery', podScopeBody($shipment, $unit))
        ->assertOk()
        ->assertJsonPath('data.status', 'DELIVERED');
});

it('rechaza con 403 a una tienda sobre un envío de otro nodo', function (): void {
    $otherNode = Uuid::generate()->value;
    $shipment = Uuid::generate()->value;
    $vehicle = Uuid::generate()->value;
    $unit = 'U-1';

    ShipmentRecord::factory()->forNode($otherNode)->create(['id' => $shipment]);
    podScopeOpenDelivery($shipment, $vehicle, $unit);
    podScopeActingAsStore(Uuid::generate()->value); // nodo asignado distinto del destino del envío

    test()->postJson('/api/v1/app/deliveries/proof-of-delivery', podScopeBody($shipment, $unit))
        ->assertStatus(403)
        ->assertJsonPath('code', 'forbidden');
});

it('rechaza con 403 a una tienda sobre un envío sin nodo destino', function (): void {
    $shipment = Uuid::generate()->value;
    $vehicle = Uuid::generate()->value;
    $unit = 'U-1';

    ShipmentRecord::factory()->create(['id' => $shipment]); // destination_node_id = null
    podScopeOpenDelivery($shipment, $vehicle, $unit);
    podScopeActingAsStore(Uuid::generate()->value);

    test()->postJson('/api/v1/app/deliveries/proof-of-delivery', podScopeBody($shipment, $unit))
        ->assertStatus(403)
        ->assertJsonPath('code', 'forbidden');
});
