<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Http\Middleware\EnsureDeviceToken;
use App\Http\Middleware\IdempotentRequests;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Logistics\Labels\Domain\ValueObject\Fraction;
use Logistics\Labels\Domain\ValueObject\LabelPayload;
use Logistics\Labels\Domain\ValueObject\SignedLabel;
use Logistics\Shared\Domain\ValueObject\Uuid;
use Logistics\Shipments\Infrastructure\Persistence\Eloquent\ShipmentLineRecord;
use Logistics\Shipments\Infrastructure\Persistence\Eloquent\ShipmentRecord;
use Logistics\Tracking\Infrastructure\Persistence\Eloquent\TrackingRecord;

/**
 * GET /app/orders/lookup?q= — consulta ABIERTA de pedidos (solo lectura, sin alcance por rol): por código
 * de pedido o por el identificador de una etiqueta. Cada envío trae su avance (`progress`), el seguimiento
 * resumido (`tracking`, con las paradas que faltan) y `can_process` según quién consulta. Contra PostgreSQL
 * (base logistics_test).
 */
beforeEach(function (): void {
    $this->withoutMiddleware([EnsureDeviceToken::class, IdempotentRequests::class]);
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

/** Usuario de la app con ese rol, ya autenticado (Sanctum). */
function lookupActingAs(Role $role, array $links = []): User
{
    $user = User::factory()->create();
    $user->assignRole($role);
    if ($links !== []) {
        $user->forceFill($links)->save();
    }
    test()->actingAs($user, 'sanctum');

    return $user;
}

/** Código de pedido único por test. */
function lookupOrderCode(string $prefix = 'LKP'): string
{
    return $prefix.'-'.strtoupper(substr(str_replace('-', '', Uuid::generate()->value), -10));
}

/** Nodo de red de tipo tienda con ese nombre; devuelve su id. */
function lookupStoreNode(string $name): string
{
    $id = Uuid::generate()->value;
    DB::table('network_nodes')->insert([
        'id' => $id,
        'code' => 'T-'.Str::upper(Str::random(6)),
        'name' => $name,
        'type' => 'store',
        'lat' => -17.78,
        'lng' => -63.18,
        'status' => 'active',
    ]);

    return $id;
}

/** Vehículo de flota con esa placa; devuelve su id. */
function lookupVehicle(string $plate): string
{
    $id = Uuid::generate()->value;
    DB::table('fleet_vehicles')->insert([
        'id' => $id,
        'plate' => $plate,
        'capacity_kg' => 3000,
        'capacity_m3' => 12,
        'home_base_node_id' => Uuid::generate()->value,
        'status' => 'available',
        'documents' => '[]',
    ]);

    return $id;
}

/** Ruta del chofer para ese vehículo; devuelve su id. */
function lookupRoute(string $vehicleId, ?string $driverId = null, string $status = 'PLANNED'): string
{
    $id = Uuid::generate()->value;
    DB::table('execution_driver_routes')->insert([
        'id' => $id, 'vehicle_id' => $vehicleId, 'route_date' => '2026-11-14', 'status' => $status,
        'driver_id' => $driverId, 'created_at' => now(), 'updated_at' => now(),
    ]);

    return $id;
}

/** Parada de un envío en una ruta. */
function lookupStop(string $routeId, string $shipmentId, int $sequence, string $status = 'PENDING'): void
{
    DB::table('execution_driver_route_stops')->insert([
        'id' => Uuid::generate()->value, 'route_id' => $routeId, 'shipment_id' => $shipmentId,
        'sequence' => $sequence, 'status' => $status, 'created_at' => now(), 'updated_at' => now(),
    ]);
}

it('encuentra el pedido por su código y devuelve datos legibles, avance, seguimiento y can_process', function (): void {
    lookupActingAs(Role::Supervisor);

    $node = lookupStoreNode('Tienda Equipetrol');
    $order = lookupOrderCode();
    $shipment = ShipmentRecord::factory()->forNode($node)->create([
        'external_id' => $order,
        'order_reference' => $order,
        'customer_name' => 'María Fernández',
    ]);
    ShipmentLineRecord::factory()->create([
        'shipment_id' => $shipment->id, 'sku' => 'SKU-TV55', 'product_name' => 'Televisor 55 pulgadas', 'quantity' => 1,
    ]);
    ShipmentRecord::factory()->create(); // otro pedido: no debe aparecer

    // El código se acepta en cualquier capitalización.
    $response = $this->getJson('/api/v1/app/orders/lookup?q='.strtolower($order))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('meta.count', 1)
        ->assertJsonPath('meta.matched_by', 'code')
        ->assertJsonPath('meta.matched_shipment_id', null)
        ->assertJsonPath('data.0.id', $shipment->id)
        ->assertJsonPath('data.0.external_id', $order)
        ->assertJsonPath('data.0.order_reference', $order)
        ->assertJsonPath('data.0.customer_name', 'María Fernández')
        ->assertJsonPath('data.0.status', 'RECEIVED')
        ->assertJsonPath('data.0.delivery_mode', 'store_pickup')
        ->assertJsonPath('data.0.destination_node.id', $node)
        ->assertJsonPath('data.0.destination_node.name', 'Tienda Equipetrol')
        ->assertJsonPath('data.0.lines.0.sku', 'SKU-TV55')
        ->assertJsonPath('data.0.lines.0.product_name', 'Televisor 55 pulgadas')
        ->assertJsonPath('data.0.tracking.stage', 'received')
        ->assertJsonPath('data.0.tracking.stops_before', null)
        ->assertJsonPath('data.0.tracking.current_node', null)
        ->assertJsonPath('data.0.can_process', true);

    expect(array_keys($response->json('data.0.progress')))->toBe([
        'vehicle_id', 'plate', 'assigned_at', 'prepared_at', 'loaded_at', 'delivered_at',
        'labels_emitted_count', 'execution_status', 'ready_for_pickup_at',
    ])
        ->and(array_keys($response->json('data.0.tracking')))->toBe([
            'stage', 'stops_before', 'eta_at', 'vehicle_plate', 'current_node',
        ]);
});

it('devuelve los envíos hermanos de un pedido con varios destinos (misma referencia de orden)', function (): void {
    lookupActingAs(Role::Preparador);

    $order = lookupOrderCode('MULTI');
    $first = ShipmentRecord::factory()->create(['external_id' => $order.'-1', 'order_reference' => $order])->id;
    $second = ShipmentRecord::factory()->create(['external_id' => $order.'-2', 'order_reference' => $order])->id;
    ShipmentRecord::factory()->create(['order_reference' => lookupOrderCode('OTRO')]);

    // Por la referencia de la orden: sus dos envíos.
    $byOrder = collect($this->getJson('/api/v1/app/orders/lookup?q='.$order)->assertOk()->json('data'))->pluck('id')->all();
    expect($byOrder)->toEqualCanonicalizing([$first, $second]);

    // Por el código de UNO de los envíos: también trae al hermano.
    $bySibling = collect($this->getJson('/api/v1/app/orders/lookup?q='.$order.'-2')->assertOk()->json('data'))->pluck('id')->all();
    expect($bySibling)->toEqualCanonicalizing([$first, $second]);
});

it('encuentra el pedido por el identificador de una etiqueta escaneada', function (): void {
    // Chofer vinculado a su ficha de flota (una cuenta de chofer sin vínculo no queda acotada por asignación).
    lookupActingAs(Role::Chofer, ['driver_id' => Uuid::generate()->value]);

    $order = lookupOrderCode('LBL');
    $shipment = ShipmentRecord::factory()->create(['external_id' => $order, 'order_reference' => $order]);
    ShipmentLineRecord::factory()->create(['shipment_id' => $shipment->id, 'sku' => 'SKU-REF01', 'product_name' => 'Refrigerador']);

    $labelId = strtoupper((string) Str::ulid());
    $signed = SignedLabel::of(
        LabelPayload::forPackage(0, $labelId, $order, 1, 'SKU-REF01', Fraction::of(1, 1), Fraction::of(1, 1), 'CD-SCZ', 'C', 1763100000),
        str_repeat('a', SignedLabel::SIGNATURE_BYTES),
    );
    DB::table('labels_emitted')->insert([
        'id' => Uuid::generate()->value, 'order_id' => $order, 'label_id' => $labelId, 'key_id' => 0,
        'zpl' => '^XA^XZ', 'signed_json' => $signed->toQrContent(), 'created_at' => now(),
    ]);

    $this->getJson('/api/v1/app/orders/lookup?q='.$labelId)
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('meta.matched_by', 'label')
        ->assertJsonPath('meta.matched_shipment_id', $shipment->id)
        ->assertJsonPath('data.0.id', $shipment->id)
        ->assertJsonPath('data.0.external_id', $order)
        ->assertJsonPath('data.0.lines.0.product_name', 'Refrigerador')
        // Un chofer sin ese pedido en su ruta lo ve igual, en modo solo consulta.
        ->assertJsonPath('data.0.can_process', false);
});

it('una tienda de OTRO nodo igual ve el pedido, con can_process=false; la tienda destino lo puede operar', function (): void {
    $destination = lookupStoreNode('Tienda Norte');
    $other = lookupStoreNode('Tienda Sur');
    $order = lookupOrderCode('STORE');
    $shipment = ShipmentRecord::factory()->forNode($destination)->create(['external_id' => $order, 'order_reference' => $order]);

    lookupActingAs(Role::Tienda, ['node_id' => $other]);

    // El listado con alcance NO lo muestra a la otra tienda…
    $this->getJson('/api/v1/app/shipments?q='.$order)->assertOk()->assertJsonCount(0, 'data');

    // …pero la consulta abierta sí, marcado como solo consulta.
    $this->getJson('/api/v1/app/orders/lookup?q='.$order)
        ->assertOk()
        ->assertJsonPath('data.0.id', $shipment->id)
        ->assertJsonPath('data.0.destination_node.name', 'Tienda Norte')
        ->assertJsonPath('data.0.can_process', false);

    lookupActingAs(Role::Tienda, ['node_id' => $destination]);

    $this->getJson('/api/v1/app/orders/lookup?q='.$order)
        ->assertOk()
        ->assertJsonPath('data.0.can_process', true);
});

it('informa a cuántas paradas está un pedido en ruta según la proyección de seguimiento', function (): void {
    lookupActingAs(Role::Tienda, ['node_id' => Uuid::generate()->value]);

    $order = lookupOrderCode('ROUTE');
    $shipment = ShipmentRecord::factory()->create([
        'external_id' => $order, 'order_reference' => $order, 'status' => 'IN_EXECUTION',
    ]);

    $vehicleId = lookupVehicle('LKP-'.strtoupper(Str::random(4)));
    $plate = DB::table('fleet_vehicles')->where('id', $vehicleId)->value('plate');
    $routeId = lookupRoute($vehicleId, null, 'IN_PROGRESS');
    lookupStop($routeId, $shipment->id, 4);

    TrackingRecord::factory()->create([
        'shipment_id' => $shipment->id,
        'order_reference' => $order,
        'status' => 'ON_THE_WAY',
        'route_id' => $routeId,
        'route_position' => 4,
        'remaining_stops' => 3,
        'eta' => '2026-11-14T15:30:00+00:00',
    ]);

    $response = $this->getJson('/api/v1/app/orders/lookup?q='.$order)
        ->assertOk()
        ->assertJsonPath('data.0.tracking.stage', 'on_the_way')
        ->assertJsonPath('data.0.tracking.stops_before', 3)
        ->assertJsonPath('data.0.tracking.vehicle_plate', $plate)
        ->assertJsonPath('data.0.tracking.current_node', null)
        ->assertJsonPath('data.0.progress.plate', $plate)
        ->assertJsonPath('data.0.can_process', false);

    expect($response->json('data.0.tracking.eta_at'))->toStartWith('2026-11-14T');
});

it('cuenta las paradas pendientes sobre la ruta real cuando el seguimiento todavía no ubicó al envío', function (): void {
    $driverId = Uuid::generate()->value;
    lookupActingAs(Role::Chofer, ['driver_id' => $driverId]);

    $order = lookupOrderCode('LIVE');
    $shipment = ShipmentRecord::factory()->create(['external_id' => $order, 'order_reference' => $order, 'status' => 'PLANNED']);

    $routeId = lookupRoute(lookupVehicle('LIV-'.strtoupper(Str::random(4))), $driverId);
    lookupStop($routeId, ShipmentRecord::factory()->create()->id, 1, 'COMPLETED'); // ya atendida: no cuenta
    lookupStop($routeId, ShipmentRecord::factory()->create()->id, 2);
    lookupStop($routeId, ShipmentRecord::factory()->create()->id, 3);
    lookupStop($routeId, $shipment->id, 4);
    lookupStop($routeId, ShipmentRecord::factory()->create()->id, 5); // va después: no cuenta

    $this->getJson('/api/v1/app/orders/lookup?q='.$order)
        ->assertOk()
        ->assertJsonPath('data.0.tracking.stage', 'planned')
        ->assertJsonPath('data.0.tracking.stops_before', 2)
        // El pedido va en una ruta vigente de este chofer: lo puede operar.
        ->assertJsonPath('data.0.can_process', true);
});

it('un pedido que el chofer ya dejó en su tienda figura en la tienda y sin paradas por delante', function (): void {
    lookupActingAs(Role::Admin);

    $store = lookupStoreNode('Tienda Centro');
    $order = lookupOrderCode('DROP');
    $shipment = ShipmentRecord::factory()->forNode($store)->create([
        'external_id' => $order, 'order_reference' => $order, 'status' => 'IN_EXECUTION',
    ]);
    DB::table('execution_deliveries')->insert([
        'id' => Uuid::generate()->value, 'shipment_id' => $shipment->id, 'vehicle_id' => lookupVehicle('DRP-'.strtoupper(Str::random(4))),
        'status' => 'IN_TRANSIT', 'store_dropped_at' => '2026-11-14T12:00:00+00:00', 'store_drop_node_id' => $store,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    TrackingRecord::factory()->create([
        'shipment_id' => $shipment->id, 'status' => 'ON_THE_WAY', 'route_id' => Uuid::generate()->value,
        'route_position' => 2, 'remaining_stops' => 1,
    ]);

    $this->getJson('/api/v1/app/orders/lookup?q='.$order)
        ->assertOk()
        ->assertJsonPath('data.0.tracking.stage', 'at_store')
        ->assertJsonPath('data.0.tracking.stops_before', null)
        ->assertJsonPath('data.0.tracking.current_node.id', $store)
        ->assertJsonPath('data.0.tracking.current_node.name', 'Tienda Centro');
});

it('responde 404 con el contrato de error cuando no hay ningún pedido para lo buscado', function (): void {
    lookupActingAs(Role::Tienda, ['node_id' => Uuid::generate()->value]);

    $response = $this->getJson('/api/v1/app/orders/lookup?q=NO-EXISTE-'.strtoupper(Str::random(8)))
        ->assertStatus(404)
        ->assertJsonPath('status', 404)
        ->assertJsonPath('code', 'not_found')
        ->assertJsonPath('error.code', 'not_found');

    expect($response->json('detail'))->toBeString()->not->toBe('')
        ->and($response->json('error.message'))->toBe($response->json('detail'));

    // Un texto con forma de UUID que no es de ningún envío tampoco rompe la consulta.
    $this->getJson('/api/v1/app/orders/lookup?q='.Uuid::generate()->value)->assertStatus(404);
});

it('exige el parámetro q y autenticación', function (): void {
    $this->getJson('/api/v1/app/orders/lookup?q=X')->assertStatus(401);

    lookupActingAs(Role::Transportista);

    $this->getJson('/api/v1/app/orders/lookup')->assertStatus(422);
});
