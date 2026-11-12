<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Http\Middleware\EnsureDeviceToken;
use App\Http\Middleware\IdempotentRequests;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Logistics\Shared\Domain\ValueObject\Uuid;
use Logistics\Shipments\Infrastructure\Persistence\Eloquent\ShipmentRecord;

/**
 * GET /app/shipments?pending_preparation=true (auditoría app #1b): solo los envíos ASIGNADOS (PLANNED /
 * IN_EXECUTION) cuya entrega aún no tiene escaneo de PREPARACIÓN, aunque ya tengan etiquetas emitidas.
 * Filtro SQL (NOT EXISTS), aditivo y combinable con los demás. Contra PostgreSQL (base logistics_test).
 */
beforeEach(function (): void {
    $this->withoutMiddleware([EnsureDeviceToken::class, IdempotentRequests::class]);
    config(['logistics-shipments.read_cache.ttl' => 0]);

    $user = User::factory()->create();
    $user->assignRole(Role::Supervisor);
    $this->actingAs($user, 'sanctum');
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

/**
 * Crea la entrega en ejecución del envío con los escaneos dados (tipo => cantidad 1).
 *
 * @param list<string> $scanTypes
 */
function pendingPrepDelivery(string $shipmentId, array $scanTypes): void
{
    $deliveryId = Uuid::generate()->value;
    DB::table('execution_deliveries')->insert([
        'id' => $deliveryId, 'shipment_id' => $shipmentId, 'vehicle_id' => Uuid::generate()->value,
        'status' => 'PENDING', 'created_at' => now(), 'updated_at' => now(),
    ]);

    foreach ($scanTypes as $type) {
        DB::table('execution_scan_events')->insert([
            'id' => Uuid::generate()->value, 'delivery_id' => $deliveryId, 'type' => $type, 'unit_id' => 'U-1',
            'lat' => -17.78, 'lng' => -63.18, 'device_id' => 'device-01', 'user_id' => 'user-07',
            'occurred_at' => '2026-11-12T09:00:00+00:00', 'idempotency_key' => Uuid::generate()->value,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}

it('lista solo los asignados sin escaneo de preparación (con o sin etiquetas emitidas)', function (): void {
    $node = Uuid::generate()->value;
    $order = 'ORD-PREP-'.strtoupper(substr(Uuid::generate()->value, -6));

    // Pendientes: asignado sin entrega; asignado con etiquetas emitidas pero sin preparar; en ejecución solo con otro escaneo.
    $planned = ShipmentRecord::factory()->forNode($node)->create(['status' => 'PLANNED'])->id;
    $labelled = ShipmentRecord::factory()->forNode($node)->create([
        'status' => 'PLANNED', 'external_id' => $order, 'order_reference' => $order,
    ])->id;
    DB::table('labels_emitted')->insert([
        'id' => Uuid::generate()->value, 'order_id' => $order, 'label_id' => 'LBL-'.$order.'-1',
        'key_id' => 0, 'zpl' => '^XA^XZ', 'signed_json' => '{}', 'created_at' => now(),
    ]);
    pendingPrepDelivery($labelled, []);
    $inExecution = ShipmentRecord::factory()->forNode($node)->create(['status' => 'IN_EXECUTION'])->id;
    pendingPrepDelivery($inExecution, ['LOAD']);

    // NO pendientes: ya preparado; recibido (sin asignar); entregado.
    $prepared = ShipmentRecord::factory()->forNode($node)->create(['status' => 'PLANNED'])->id;
    pendingPrepDelivery($prepared, ['PREPARATION']);
    ShipmentRecord::factory()->forNode($node)->create(['status' => 'RECEIVED']);
    ShipmentRecord::factory()->forNode($node)->create(['status' => 'DELIVERED']);

    $ids = fn (string $query): array => collect(
        $this->getJson("/api/v1/app/shipments?destination_node_id={$node}&{$query}")->assertOk()->json('data'),
    )->pluck('id')->all();

    expect($ids('pending_preparation=true'))->toEqualCanonicalizing([$planned, $labelled, $inExecution])
        ->and($ids('pending_preparation=TRUE'))->toEqualCanonicalizing([$planned, $labelled, $inExecution])
        ->and($ids('pending_preparation=1&status=IN_EXECUTION'))->toBe([$inExecution])
        ->and($ids('pending_preparation=false'))->toHaveCount(6);

    // Paginado (offset y cursor) usa el mismo filtro.
    $this->getJson("/api/v1/app/shipments?destination_node_id={$node}&pending_preparation=true&page=1&per_page=10")
        ->assertOk()
        ->assertJsonPath('meta.total', 3);
    $this->getJson("/api/v1/app/shipments?destination_node_id={$node}&pending_preparation=true&cursor=")
        ->assertOk()
        ->assertJsonCount(3, 'data');
});

it('rechaza con 422 un valor que no es booleano', function (): void {
    $this->getJson('/api/v1/app/shipments?pending_preparation=quizas')
        ->assertStatus(422);
});
