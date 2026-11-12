<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Http\Middleware\EnsureDeviceToken;
use App\Http\Middleware\IdempotentRequests;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Logistics\Execution\Application\Command\RecordLoadScan\RecordLoadScan;
use Logistics\Execution\Application\Command\RecordPreparationScan\RecordPreparationScan;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Domain\ValueObject\Uuid;
use Logistics\Shipments\Infrastructure\Persistence\Eloquent\ShipmentRecord;

/**
 * Retiro en tienda: el mostrador NO exige el QR del cliente (igual que el chofer y el transportista ya no lo
 * exigen). Sin `confirmation` se registra el marcador STORE_PICKUP_HANDOVER; la firma o el documento del
 * cliente, si se capturan, viajan como evidencia aparte. Los demás roles internos siguen exigiéndola.
 */
beforeEach(function (): void {
    $this->withoutMiddleware([EnsureDeviceToken::class, IdempotentRequests::class]);
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

function pickupNoQrActingAs(Role $role, ?string $nodeId): void
{
    $user = User::factory()->create();
    $user->assignRole($role);
    $user->forceFill(['node_id' => $nodeId])->save();
    test()->actingAs($user, 'sanctum');
}

/**
 * Envío de la tienda con su entrega lista para el POD (preparado y cargado).
 */
function pickupNoQrDelivery(string $node, string $unit): string
{
    $shipment = Uuid::generate()->value;
    ShipmentRecord::factory()->forNode($node)->create(['id' => $shipment]);

    /** @var CommandBus $bus */
    $bus = app(CommandBus::class);
    $bus->dispatch(new RecordPreparationScan(
        shipmentId: $shipment,
        vehicleId: Uuid::generate()->value,
        unitId: $unit,
        lat: -16.5,
        lng: -68.15,
        deviceId: 'device-01',
        userId: 'user-07',
        occurredAt: '2026-11-12T08:00:00+00:00',
        idempotencyKey: 'prep-'.$shipment,
    ));
    $bus->dispatch(new RecordLoadScan(
        shipmentId: $shipment,
        unitId: $unit,
        lat: -16.5,
        lng: -68.15,
        deviceId: 'device-01',
        userId: 'user-07',
        occurredAt: '2026-11-12T08:30:00+00:00',
        idempotencyKey: 'load-'.$shipment,
    ));

    return $shipment;
}

/**
 * Cuerpo del POD de mostrador SIN confirmación (solo el escaneo del producto + la foto).
 *
 * @return array<string, mixed>
 */
function pickupNoQrBody(string $shipment, string $unit): array
{
    return [
        'shipment_id' => $shipment,
        'unit_id' => $unit,
        'photo_path' => 'evidence/pickup.jpg',
        'occurred_at' => '2026-11-12T11:00:00+00:00',
        'device_id' => 'device-01',
        'user_id' => 'user-07',
        'idempotency_key' => 'pod-'.$shipment,
    ];
}

it('lets the store counter close a pickup without the customer QR', function (): void {
    $node = Uuid::generate()->value;
    $shipment = pickupNoQrDelivery($node, 'U-1');
    pickupNoQrActingAs(Role::Tienda, $node);

    $this->postJson('/api/v1/app/deliveries/proof-of-delivery', pickupNoQrBody($shipment, 'U-1'))
        ->assertOk()
        ->assertJsonPath('data.status', 'DELIVERED');

    expect(DB::table('execution_deliveries')->where('shipment_id', $shipment)->value('pod_confirmation'))
        ->toBe('STORE_PICKUP_HANDOVER');
});

it('keeps a confirmation the store counter does send (signature marker or document)', function (): void {
    $node = Uuid::generate()->value;
    $shipment = pickupNoQrDelivery($node, 'U-1');
    pickupNoQrActingAs(Role::Tienda, $node);

    $this->postJson('/api/v1/app/deliveries/proof-of-delivery', pickupNoQrBody($shipment, 'U-1') + ['confirmation' => 'FIRMA_DIGITAL'])
        ->assertOk();

    expect(DB::table('execution_deliveries')->where('shipment_id', $shipment)->value('pod_confirmation'))
        ->toBe('FIRMA_DIGITAL');
});

it('still requires the confirmation for other internal roles', function (): void {
    $node = Uuid::generate()->value;
    $shipment = pickupNoQrDelivery($node, 'U-1');
    pickupNoQrActingAs(Role::Supervisor, null);

    $this->postJson('/api/v1/app/deliveries/proof-of-delivery', pickupNoQrBody($shipment, 'U-1'))
        ->assertStatus(422);
});
