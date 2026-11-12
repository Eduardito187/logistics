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
 * GET /app/shipments?pending_preparation=true: solo los envíos ASIGNADOS (PLANNED / IN_EXECUTION) que aún
 * NO tienen entrega en ejecución abierta. Flujo vigente: asignar el vehículo abre la entrega = preparar, así
 * que la existencia de la entrega saca al envío de la lista (las etiquetas emitidas NO abren entrega, no
 * preparan). Filtro SQL (NOT EXISTS), aditivo y combinable. Contra PostgreSQL (base logistics_test).
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

it('lista solo los asignados SIN entrega abierta (etiquetas no preparan; asignar vehículo sí)', function (): void {
    $node = Uuid::generate()->value;
    $order = 'ORD-PREP-'.strtoupper(substr(Uuid::generate()->value, -6));

    // PENDIENTES: asignado sin entrega; con etiquetas emitidas pero SIN entrega (las etiquetas no preparan).
    $planned = ShipmentRecord::factory()->forNode($node)->create(['status' => 'PLANNED'])->id;
    $labelled = ShipmentRecord::factory()->forNode($node)->create([
        'status' => 'PLANNED', 'external_id' => $order, 'order_reference' => $order,
    ])->id;
    DB::table('labels_emitted')->insert([
        'id' => Uuid::generate()->value, 'order_id' => $order, 'label_id' => 'LBL-'.$order.'-1',
        'key_id' => 0, 'zpl' => '^XA^XZ', 'signed_json' => '{}', 'created_at' => now(),
    ]);

    // NO pendientes: con entrega abierta (vehículo asignado = preparado, aunque siga PENDING sin escanear);
    // en ejecución con entrega; recibido (sin asignar); entregado.
    $assigned = ShipmentRecord::factory()->forNode($node)->create(['status' => 'PLANNED'])->id;
    pendingPrepDelivery($assigned, []);
    $inExecution = ShipmentRecord::factory()->forNode($node)->create(['status' => 'IN_EXECUTION'])->id;
    pendingPrepDelivery($inExecution, ['LOAD']);
    ShipmentRecord::factory()->forNode($node)->create(['status' => 'RECEIVED']);
    ShipmentRecord::factory()->forNode($node)->create(['status' => 'DELIVERED']);

    $ids = fn (string $query): array => collect(
        $this->getJson("/api/v1/app/shipments?destination_node_id={$node}&{$query}")->assertOk()->json('data'),
    )->pluck('id')->all();

    expect($ids('pending_preparation=true'))->toEqualCanonicalizing([$planned, $labelled])
        ->and($ids('pending_preparation=TRUE'))->toEqualCanonicalizing([$planned, $labelled])
        // IN_EXECUTION siempre tiene entrega abierta (así entró en ejecución) → nunca pendiente de preparar.
        ->and($ids('pending_preparation=1&status=IN_EXECUTION'))->toBe([])
        ->and($ids('pending_preparation=false'))->toHaveCount(6);

    // Paginado (offset y cursor) usa el mismo filtro.
    $this->getJson("/api/v1/app/shipments?destination_node_id={$node}&pending_preparation=true&page=1&per_page=10")
        ->assertOk()
        ->assertJsonPath('meta.total', 2);
    $this->getJson("/api/v1/app/shipments?destination_node_id={$node}&pending_preparation=true&cursor=")
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

it('rechaza con 422 un valor que no es booleano', function (): void {
    $this->getJson('/api/v1/app/shipments?pending_preparation=quizas')
        ->assertStatus(422);
});
