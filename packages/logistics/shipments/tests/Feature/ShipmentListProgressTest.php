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
 * GET /app/shipments: el estado se acepta en cualquier capitalización (422 solo para valores
 * desconocidos), filtra por rango de fecha prometida y cada ítem trae su objeto `progress` (camión,
 * patente, horas de preparado/cargado/entregado, etiquetas emitidas y estado de la entrega). Contra
 * PostgreSQL (base logistics_test).
 */
beforeEach(function (): void {
    $this->withoutMiddleware([EnsureDeviceToken::class, IdempotentRequests::class]);

    $user = User::factory()->create();
    $user->assignRole(Role::Supervisor);
    $this->actingAs($user, 'sanctum');
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

it('acepta el filtro de estado en minúsculas y en mayúsculas por igual', function (): void {
    $node = Uuid::generate()->value;
    ShipmentRecord::factory()->forNode($node)->create(['status' => 'PLANNED']);
    ShipmentRecord::factory()->forNode($node)->create(['status' => 'RECEIVED']);

    foreach (['planned', 'PLANNED', 'Planned'] as $status) {
        $this->getJson("/api/v1/app/shipments?destination_node_id={$node}&status={$status}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', 'PLANNED');
    }
});

it('devuelve 422 solo ante un estado realmente desconocido', function (): void {
    $this->getJson('/api/v1/app/shipments?status=archivado')
        ->assertStatus(422);
});

it('filtra por rango de fecha prometida de entrega (ambos extremos inclusive)', function (): void {
    $node = Uuid::generate()->value;
    $early = ShipmentRecord::factory()->forNode($node)->withDeliverySlot('2026-10-05', '09:00', '13:00')->create()->id;
    $mid = ShipmentRecord::factory()->forNode($node)->withDeliverySlot('2026-11-10', '09:00', '13:00')->create()->id;
    $late = ShipmentRecord::factory()->forNode($node)->withDeliverySlot('2026-11-20', '09:00', '13:00')->create()->id;
    ShipmentRecord::factory()->forNode($node)->create(); // sin fecha: queda fuera con cualquier cota

    $ids = fn (string $query): array => collect(
        $this->getJson("/api/v1/app/shipments?destination_node_id={$node}&{$query}")->assertOk()->json('data'),
    )->pluck('id')->all();

    expect($ids('delivery_date_from=2026-11-01'))->toEqualCanonicalizing([$mid, $late])
        ->and($ids('delivery_date_to=2026-11-10'))->toEqualCanonicalizing([$early, $mid])
        ->and($ids('delivery_date_from=2026-11-10&delivery_date_to=2026-11-10'))->toBe([$mid]);

    $this->getJson('/api/v1/app/shipments?delivery_date_from=2026-11-10&delivery_date_to=2026-11-01')
        ->assertStatus(422);
    $this->getJson('/api/v1/app/shipments?delivery_date_from=10/11/2026')
        ->assertStatus(422);
});

it('agrega a cada ítem su avance operativo (camión, horas, etiquetas, estado de entrega)', function (): void {
    $node = Uuid::generate()->value;
    $order = 'ORD-PROG-'.strtoupper(substr(Uuid::generate()->value, -6));

    $shipmentId = ShipmentRecord::factory()->forNode($node)->create([
        'status' => 'IN_EXECUTION',
        'external_id' => $order,
        'order_reference' => $order,
    ])->id;
    $bare = ShipmentRecord::factory()->forNode($node)->create()->id;

    $vehicleId = Uuid::generate()->value;
    DB::table('fleet_vehicles')->insert([
        'id' => $vehicleId,
        'plate' => 'PRG-'.strtoupper(substr($vehicleId, -4)),
        'capacity_kg' => 3000,
        'capacity_m3' => 12,
        'home_base_node_id' => Uuid::generate()->value,
        'status' => 'available',
        'documents' => '[]',
    ]);

    $routeId = Uuid::generate()->value;
    DB::table('execution_driver_routes')->insert([
        'id' => $routeId, 'vehicle_id' => $vehicleId, 'route_date' => '2026-11-12',
        'status' => 'PLANNED', 'created_at' => '2026-11-12 08:00:00', 'updated_at' => '2026-11-12 08:00:00',
    ]);
    DB::table('execution_driver_route_stops')->insert([
        'id' => Uuid::generate()->value, 'route_id' => $routeId, 'shipment_id' => $shipmentId, 'sequence' => 1,
        'status' => 'PENDING', 'created_at' => '2026-11-12 08:05:00', 'updated_at' => '2026-11-12 08:05:00',
    ]);

    $deliveryId = Uuid::generate()->value;
    DB::table('execution_deliveries')->insert([
        'id' => $deliveryId, 'shipment_id' => $shipmentId, 'vehicle_id' => $vehicleId, 'status' => 'LOADED',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    foreach (['PREPARATION' => '2026-11-12T09:00:00+00:00', 'LOAD' => '2026-11-12T10:00:00+00:00'] as $type => $at) {
        DB::table('execution_scan_events')->insert([
            'id' => Uuid::generate()->value, 'delivery_id' => $deliveryId, 'type' => $type, 'unit_id' => 'U-1',
            'lat' => -17.78, 'lng' => -63.18, 'device_id' => 'device-01', 'user_id' => 'user-07',
            'occurred_at' => $at, 'idempotency_key' => Uuid::generate()->value,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    foreach ([1, 2] as $n) {
        DB::table('labels_emitted')->insert([
            'id' => Uuid::generate()->value, 'order_id' => $order, 'label_id' => 'LBL-'.$order.'-'.$n,
            'key_id' => 0, 'zpl' => '^XA^XZ', 'signed_json' => '{}', 'created_at' => now(),
        ]);
    }

    $data = collect($this->getJson("/api/v1/app/shipments?destination_node_id={$node}")->assertOk()->json('data'))
        ->keyBy('id');

    expect($data[$shipmentId]['progress'])->toMatchArray([
        'vehicle_id' => $vehicleId,
        'plate' => 'PRG-'.strtoupper(substr($vehicleId, -4)),
        'labels_emitted_count' => 2,
        'execution_status' => 'LOADED',
        'delivered_at' => null,
    ])
        ->and($data[$shipmentId]['progress']['assigned_at'])->not->toBeNull()
        ->and($data[$shipmentId]['progress']['prepared_at'])->toStartWith('2026-11-12T')
        ->and($data[$shipmentId]['progress']['loaded_at'])->toStartWith('2026-11-12T');

    // Un envío sin rastros trae el objeto con la MISMA forma y campos null (etiquetas: 0, se conoce).
    expect(array_keys($data[$bare]['progress']))->toBe([
        'vehicle_id', 'plate', 'assigned_at', 'prepared_at', 'loaded_at', 'delivered_at',
        'labels_emitted_count', 'execution_status', 'ready_for_pickup_at',
    ])
        ->and($data[$bare]['progress']['vehicle_id'])->toBeNull()
        ->and($data[$bare]['progress']['execution_status'])->toBeNull()
        ->and($data[$bare]['progress']['labels_emitted_count'])->toBe(0);
});

it('también agrega el avance en la paginación por cursor', function (): void {
    $node = Uuid::generate()->value;
    ShipmentRecord::factory()->forNode($node)->create();

    $this->getJson("/api/v1/app/shipments?destination_node_id={$node}&per_page=5&cursor=")
        ->assertOk()
        ->assertJsonStructure(['data' => [['id', 'status', 'progress' => ['vehicle_id', 'plate', 'execution_status']]]]);
});
