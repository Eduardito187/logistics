<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Http\Middleware\EnsureDeviceToken;
use App\Http\Middleware\IdempotentRequests;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Logistics\Shared\Domain\ValueObject\Uuid;
use Logistics\Shipments\Infrastructure\Persistence\Eloquent\ShipmentRecord;

/**
 * ALCANCE POR ROL aplicado en el servidor (punto B del pedido de la app, regla del dueño):
 *  - el preparador lista y opera los pedidos cuyo departamento de ORIGEN o de DESTINO es su ciudad;
 *  - la tienda, los que SALEN de su nodo o LLEGAN a él;
 *  - el chofer, solo los que le asignaron;
 *  - supervisor y admin, todos; un usuario sin ciudad no queda restringido;
 *  - CONSULTAR un pedido (lookup) sigue abierto para cualquiera, con `can_process=false` si no es suyo.
 * Las escrituras de operación fuera de alcance responden 403 con su `code`. Contra PostgreSQL
 * (base logistics_test).
 */
beforeEach(function (): void {
    $this->withoutMiddleware([EnsureDeviceToken::class, IdempotentRequests::class]);
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

/** Usuario de la app con ese rol y esos vínculos (city, node_id, driver_id…), ya autenticado. */
function scpActingAs(Role $role, array $links = []): User
{
    $user = User::factory()->create();
    $user->assignRole($role);
    if ($links !== []) {
        $user->forceFill($links)->save();
    }
    test()->actingAs($user, 'sanctum');

    return $user;
}

/** Nodo de red de ese tipo y departamento; devuelve su id. */
function scpNode(string $type, string $department, string $name): string
{
    $id = Uuid::generate()->value;
    DB::table('network_nodes')->insert([
        'id' => $id,
        'code' => 'SCP-'.Str::upper(Str::random(8)),
        'name' => $name,
        'type' => $type,
        'department' => $department,
        'lat' => -17.0,
        'lng' => -65.0,
        'status' => 'active',
    ]);

    return $id;
}

/** Código de pedido único por test. */
function scpOrder(string $prefix): string
{
    return $prefix.'-'.strtoupper(substr(str_replace('-', '', Uuid::generate()->value), -10));
}

/**
 * Red mínima de tres ciudades con su centro de distribución y una tienda cada una.
 *
 * @return array<string, string>
 */
function scpNetwork(): array
{
    return [
        'cd_lpz' => scpNode('distribution_center', 'la_paz', 'CD La Paz'),
        'cd_scz' => scpNode('distribution_center', 'santa_cruz', 'CD Santa Cruz'),
        'cd_cbba' => scpNode('distribution_center', 'cochabamba', 'CD Cochabamba'),
        'store_lpz' => scpNode('store', 'la_paz', 'Tienda La Paz'),
        'store_scz' => scpNode('store', 'santa_cruz', 'Tienda Santa Cruz'),
        'store_cbba' => scpNode('store', 'cochabamba', 'Tienda Cochabamba'),
    ];
}

/** Envío que sale del nodo `$origin` (o del que se derive, si es null) hacia el nodo `$destination`. */
function scpShipment(?string $origin, string $destination, string $prefix = 'SCP'): ShipmentRecord
{
    $order = scpOrder($prefix);

    return ShipmentRecord::factory()->forNode($destination)->create([
        'external_id' => $order,
        'order_reference' => $order,
        'origin_node_id' => $origin,
    ]);
}

/** Ids de los envíos que devuelve el listado de la app para el usuario autenticado. */
function scpListedIds(string $query = ''): array
{
    return collect(test()->getJson('/api/v1/app/shipments'.$query)->assertOk()->json('data'))->pluck('id')->all();
}

/** Vehículo de flota; devuelve su id. */
function scpVehicle(): string
{
    $id = Uuid::generate()->value;
    DB::table('fleet_vehicles')->insert([
        'id' => $id,
        'plate' => 'SCP-'.strtoupper(Str::random(4)),
        'capacity_kg' => 3000,
        'capacity_m3' => 12,
        'home_base_node_id' => Uuid::generate()->value,
        'status' => 'available',
        'documents' => '[]',
    ]);

    return $id;
}

/** Ruta de un vehículo (con o sin chofer grabado) y una parada por cada envío; devuelve el id de la ruta. */
function scpRoute(string $vehicleId, ?string $driverId, array $shipmentIds, string $status = 'PLANNED'): string
{
    $routeId = Uuid::generate()->value;
    DB::table('execution_driver_routes')->insert([
        'id' => $routeId, 'vehicle_id' => $vehicleId, 'route_date' => '2026-11-14', 'status' => $status,
        'driver_id' => $driverId, 'created_at' => now(), 'updated_at' => now(),
    ]);

    foreach (array_values($shipmentIds) as $index => $shipmentId) {
        DB::table('execution_driver_route_stops')->insert([
            'id' => Uuid::generate()->value, 'route_id' => $routeId, 'shipment_id' => $shipmentId,
            'sequence' => $index + 1, 'status' => 'PENDING', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    return $routeId;
}

it('guarda el origen y los departamentos del envío al crearlo', function (): void {
    $net = scpNetwork();

    // Origen informado (Cochabamba) hacia una tienda de La Paz: se respeta el origen.
    $informed = scpShipment($net['cd_cbba'], $net['store_lpz'])->refresh();
    expect($informed->origin_node_id)->toBe($net['cd_cbba'])
        ->and($informed->origin_department)->toBe('cochabamba')
        ->and($informed->destination_department)->toBe('la_paz');

    // Sin origen informado: sale del centro de distribución del departamento de destino.
    $derived = scpShipment(null, $net['store_scz'])->refresh();
    expect($derived->origin_department)->toBe('santa_cruz')
        ->and($derived->destination_department)->toBe('santa_cruz')
        ->and($derived->origin_node_id)->not->toBeNull();

    // A domicilio en El Alto: la ciudad pertenece al departamento de La Paz.
    $home = ShipmentRecord::factory()->create([
        'address_text' => 'Av. 6 de Marzo 100, El Alto', 'lat' => null, 'lng' => null,
    ])->refresh();
    expect($home->city)->toBe('El Alto')
        ->and($home->destination_department)->toBe('la_paz')
        ->and($home->origin_department)->toBe('la_paz');
});

it('el preparador de La Paz no lista un pedido Santa Cruz → Santa Cruz y sí uno Cochabamba → La Paz', function (): void {
    $net = scpNetwork();
    $local = scpShipment(null, $net['store_scz']);                    // santa_cruz → santa_cruz
    $inbound = scpShipment($net['cd_cbba'], $net['store_lpz']);       // cochabamba → la_paz
    $outbound = scpShipment($net['cd_lpz'], $net['store_cbba']);      // la_paz → cochabamba
    // Envío todavía sin alcance resuelto: no se esconde por un dato faltante.
    $unresolved = scpShipment(null, $net['store_scz']);
    DB::table('shipments')->where('id', $unresolved->id)->update([
        'origin_node_id' => null, 'origin_department' => null, 'destination_department' => null,
    ]);

    scpActingAs(Role::Preparador, ['city' => 'la_paz']);

    // Sin paginar, por offset y por cursor: el mismo alcance.
    foreach (['', '?page=1&per_page=100', '?cursor=&per_page=100'] as $mode) {
        $ids = scpListedIds($mode);
        expect($ids)->toContain($inbound->id, $outbound->id, $unresolved->id)
            ->and($ids)->not->toContain($local->id);
    }

    // El pedido de Cochabamba → La Paz también lo ve el preparador de Cochabamba; el local de Santa Cruz no.
    scpActingAs(Role::Preparador, ['city' => 'cochabamba']);
    $ids = scpListedIds();
    expect($ids)->toContain($inbound->id, $outbound->id)
        ->and($ids)->not->toContain($local->id);

    // Y el de Santa Cruz ve el suyo, no los de La Paz / Cochabamba.
    scpActingAs(Role::Preparador, ['city' => 'santa_cruz']);
    $ids = scpListedIds();
    expect($ids)->toContain($local->id)
        ->and($ids)->not->toContain($inbound->id, $outbound->id);
});

it('el conteo por estado del listado respeta el alcance del preparador', function (): void {
    $net = scpNetwork();
    scpShipment(null, $net['store_scz']);
    scpShipment($net['cd_cbba'], $net['store_lpz']);

    scpActingAs(Role::Preparador, ['city' => 'la_paz']);

    $response = test()->getJson('/api/v1/app/shipments?include=count_by_status')->assertOk();
    $visibleReceived = collect($response->json('data'))->where('status', 'RECEIVED')->count();

    expect($response->json('meta.count_by_status.RECEIVED'))->toBe($visibleReceived);
});

it('la ficha del envío trae su origen legible y responde 403 fuera de la ciudad del preparador', function (): void {
    $net = scpNetwork();
    $local = scpShipment(null, $net['store_scz']);
    $inbound = scpShipment($net['cd_cbba'], $net['store_lpz']);

    scpActingAs(Role::Preparador, ['city' => 'la_paz']);

    test()->getJson('/api/v1/app/shipments/'.$inbound->id)
        ->assertOk()
        ->assertJsonPath('data.id', $inbound->id)
        ->assertJsonPath('data.origin_node.id', $net['cd_cbba'])
        ->assertJsonPath('data.origin_node.name', 'CD Cochabamba')
        ->assertJsonPath('data.origin_department', 'cochabamba')
        ->assertJsonPath('data.destination_department', 'la_paz');

    $denied = test()->getJson('/api/v1/app/shipments/'.$local->id)
        ->assertStatus(403)
        ->assertJsonPath('status', 403)
        ->assertJsonPath('code', 'out_of_scope_city')
        ->assertJsonPath('error.code', 'out_of_scope_city');

    expect($denied->json('detail'))->toBeString()->not->toBe('')
        ->and($denied->json('error.message'))->toBe($denied->json('detail'));
});

it('responde 403 out_of_scope_city al emitir etiquetas o asignar vehículo de un pedido de otra ciudad', function (): void {
    $net = scpNetwork();
    $local = scpShipment(null, $net['store_scz']);
    $inbound = scpShipment($net['cd_cbba'], $net['store_lpz']);

    scpActingAs(Role::Preparador, ['city' => 'la_paz']);

    foreach (['labels', 'vehicle'] as $action) {
        $denied = test()->postJson('/api/v1/app/orders/'.$local->external_id.'/'.$action, [])
            ->assertStatus(403)
            ->assertJsonPath('code', 'out_of_scope_city')
            ->assertJsonPath('error.code', 'out_of_scope_city');
        expect($denied->json('error.message'))->toBe($denied->json('detail'));

        // El pedido de su ciudad pasa la guarda (lo que responda después ya es del endpoint, no del alcance).
        expect(test()->postJson('/api/v1/app/orders/'.$inbound->external_id.'/'.$action, [])->status())->not->toBe(403);
    }

    // Tampoco puede escanear la preparación ni la carga de ese envío.
    foreach (['preparation', 'load'] as $scan) {
        test()->postJson('/api/v1/app/deliveries/scans/'.$scan, ['shipment_id' => $local->id])
            ->assertStatus(403)
            ->assertJsonPath('code', 'out_of_scope_city');
    }

    // En el lote offline el ítem fuera de alcance se rechaza solo, con el mismo motivo.
    test()->postJson('/api/v1/app/deliveries/scans/batch', ['items' => [[
        'idempotency_key' => 'scp-batch-1',
        'kind' => 'load',
        'occurred_at' => '2026-11-12T08:00:00-04:00',
        'payload' => ['shipment_id' => $local->id, 'unit_id' => 'U-1', 'lat' => -17.78, 'lng' => -63.18],
    ]], 'device_id' => 'dev', 'user_id' => 'usr'])
        ->assertStatus(207)
        ->assertJsonPath('data.0.result', 'rejected')
        ->assertJsonPath('data.0.problem.code', 'out_of_scope_city');
});

it('la consulta de pedidos sigue abierta para el preparador de otra ciudad, con can_process=false', function (): void {
    $net = scpNetwork();
    $local = scpShipment(null, $net['store_scz'], 'OPEN');
    $inbound = scpShipment($net['cd_cbba'], $net['store_lpz'], 'OPEN');

    scpActingAs(Role::Preparador, ['city' => 'la_paz']);

    test()->getJson('/api/v1/app/orders/lookup?q='.$local->external_id)
        ->assertOk()
        ->assertJsonPath('data.0.id', $local->id)
        ->assertJsonPath('data.0.origin_department', 'santa_cruz')
        ->assertJsonPath('data.0.destination_department', 'santa_cruz')
        ->assertJsonPath('data.0.can_process', false);

    test()->getJson('/api/v1/app/orders/lookup?q='.$inbound->external_id)
        ->assertOk()
        ->assertJsonPath('data.0.origin_node.id', $net['cd_cbba'])
        ->assertJsonPath('data.0.origin_node.name', 'CD Cochabamba')
        ->assertJsonPath('data.0.can_process', true);
});

it('un preparador sin ciudad asignada no queda restringido', function (): void {
    $net = scpNetwork();
    $local = scpShipment(null, $net['store_scz']);
    $inbound = scpShipment($net['cd_cbba'], $net['store_lpz']);

    scpActingAs(Role::Preparador);

    expect(scpListedIds())->toContain($local->id, $inbound->id);

    test()->getJson('/api/v1/app/shipments/'.$local->id)->assertOk();
    expect(test()->postJson('/api/v1/app/orders/'.$local->external_id.'/labels', [])->status())->not->toBe(403);
    test()->getJson('/api/v1/app/orders/lookup?q='.$local->external_id)->assertOk()->assertJsonPath('data.0.can_process', true);
});

it('la tienda ve lo que sale de su nodo y lo que llega a él, sea cual sea la otra ciudad', function (): void {
    $net = scpNetwork();
    $incoming = scpShipment($net['cd_cbba'], $net['store_lpz']);        // llega a la tienda
    $outgoing = scpShipment($net['store_lpz'], $net['store_scz']);      // sale de la tienda hacia otra ciudad
    $foreign = scpShipment($net['cd_scz'], $net['store_scz']);          // ni sale ni llega

    scpActingAs(Role::Tienda, ['node_id' => $net['store_lpz']]);

    $ids = scpListedIds();
    expect($ids)->toContain($incoming->id, $outgoing->id)
        ->and($ids)->not->toContain($foreign->id);

    // El filtro de nodo destino que mande la app no amplía ni cambia el alcance de la tienda.
    expect(scpListedIds('?destination_node_id='.$net['store_scz']))->not->toContain($foreign->id);

    test()->getJson('/api/v1/app/shipments/'.$outgoing->id)->assertOk();
    test()->getJson('/api/v1/app/shipments/'.$foreign->id)
        ->assertStatus(403)
        ->assertJsonPath('code', 'out_of_scope_node');

    test()->getJson('/api/v1/app/orders/lookup?q='.$outgoing->external_id)->assertOk()->assertJsonPath('data.0.can_process', true);
    test()->getJson('/api/v1/app/orders/lookup?q='.$foreign->external_id)->assertOk()->assertJsonPath('data.0.can_process', false);
});

it('el chofer solo lista y opera los envíos que le asignaron', function (): void {
    $net = scpNetwork();
    $driverId = Uuid::generate()->value;

    $mine = scpShipment(null, $net['store_scz']);
    $byCrew = scpShipment(null, $net['store_scz']);
    $others = scpShipment(null, $net['store_scz']);

    // Ruta con el chofer grabado.
    scpRoute(scpVehicle(), $driverId, [$mine->id]);
    // Ruta sin chofer grabado: vale la tripulación del vehículo de ese día.
    $crewVehicle = scpVehicle();
    scpRoute($crewVehicle, null, [$byCrew->id]);
    DB::table('fleet_crews')->insert([
        'id' => Uuid::generate()->value, 'driver_id' => $driverId, 'helper_ids' => '[]', 'vehicle_id' => $crewVehicle,
        'assembled_for' => '2026-11-14', 'created_at' => now(), 'updated_at' => now(),
    ]);
    // Ruta de otro chofer.
    scpRoute(scpVehicle(), Uuid::generate()->value, [$others->id]);

    scpActingAs(Role::Chofer, ['driver_id' => $driverId]);

    $ids = scpListedIds();
    expect($ids)->toContain($mine->id, $byCrew->id)
        ->and($ids)->not->toContain($others->id);

    test()->getJson('/api/v1/app/shipments/'.$others->id)
        ->assertStatus(403)
        ->assertJsonPath('code', 'out_of_scope_assignment');

    // Escrituras del chofer sobre un envío ajeno: 403; sobre el suyo, pasa la guarda.
    foreach (['issues', 'complete', 'store-drop'] as $action) {
        test()->postJson('/api/v1/app/deliveries/'.$others->id.'/'.$action, [])
            ->assertStatus(403)
            ->assertJsonPath('code', 'out_of_scope_assignment');
        expect(test()->postJson('/api/v1/app/deliveries/'.$mine->id.'/'.$action, [])->status())->not->toBe(403);
    }
    test()->postJson('/api/v1/app/deliveries/proof-of-delivery', ['shipment_id' => $others->id])
        ->assertStatus(403)
        ->assertJsonPath('code', 'out_of_scope_assignment');

    // Consultar sigue abierto.
    test()->getJson('/api/v1/app/orders/lookup?q='.$others->external_id)->assertOk()->assertJsonPath('data.0.can_process', false);
    test()->getJson('/api/v1/app/orders/lookup?q='.$byCrew->external_id)->assertOk()->assertJsonPath('data.0.can_process', true);
});

it('el transportista solo lista los envíos de sus viajes', function (): void {
    $net = scpNetwork();
    $carrierId = Uuid::generate()->value;
    $mine = scpShipment(null, $net['store_scz']);
    $others = scpShipment(null, $net['store_scz']);

    $tripId = Uuid::generate()->value;
    DB::table('carrier_trips')->insert([
        'id' => $tripId, 'carrier_id' => $carrierId, 'corridor_from' => 'santa_cruz', 'corridor_to' => 'la_paz',
        'status' => 'SCHEDULED', 'scheduled_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('carrier_trip_shipments')->insert([
        'trip_id' => $tripId, 'shipment_id' => $mine->id, 'position' => 0, 'created_at' => now(),
    ]);

    scpActingAs(Role::Transportista, ['carrier_id' => $carrierId]);

    $ids = scpListedIds();
    expect($ids)->toContain($mine->id)
        ->and($ids)->not->toContain($others->id);

    test()->postJson('/api/v1/app/deliveries/proof-of-delivery', ['shipment_id' => $others->id])
        ->assertStatus(403)
        ->assertJsonPath('code', 'out_of_scope_assignment');
});

it('admin y supervisor ven y operan los pedidos de cualquier ciudad', function (): void {
    $net = scpNetwork();
    $local = scpShipment(null, $net['store_scz']);
    $inbound = scpShipment($net['cd_cbba'], $net['store_lpz']);

    foreach ([Role::Admin, Role::Supervisor] as $role) {
        // Aunque tengan ciudad asignada, no los restringe.
        scpActingAs($role, ['city' => 'la_paz']);

        expect(scpListedIds())->toContain($local->id, $inbound->id);
        test()->getJson('/api/v1/app/shipments/'.$local->id)->assertOk();
        expect(test()->postJson('/api/v1/app/orders/'.$local->external_id.'/labels', [])->status())->not->toBe(403);
        test()->getJson('/api/v1/app/orders/lookup?q='.$local->external_id)->assertOk()->assertJsonPath('data.0.can_process', true);
    }
});

it('con varios roles alcanza con que uno habilite el pedido', function (): void {
    $net = scpNetwork();
    $local = scpShipment(null, $net['store_scz']);                    // santa_cruz → santa_cruz
    $foreign = scpShipment($net['cd_cbba'], $net['store_cbba']);      // cochabamba → cochabamba

    // Preparador de La Paz que además es personal de la tienda de Santa Cruz.
    $user = scpActingAs(Role::Preparador, ['city' => 'la_paz', 'node_id' => $net['store_scz']]);
    $user->assignRole(Role::Tienda);

    $ids = scpListedIds();
    expect($ids)->toContain($local->id)
        ->and($ids)->not->toContain($foreign->id);
});

it('el relleno de alcance es simulación por defecto y con --force completa solo lo que falta', function (): void {
    $net = scpNetwork();
    $pending = scpShipment(null, $net['store_lpz']);
    $informed = scpShipment($net['cd_cbba'], $net['store_lpz']);
    DB::table('shipments')->where('id', $pending->id)->update([
        'origin_node_id' => null, 'origin_department' => null, 'destination_department' => null,
    ]);
    DB::table('shipments')->where('id', $informed->id)->update(['origin_department' => null, 'destination_department' => null]);

    $this->artisan('logistics:shipments:backfill-scope')->assertSuccessful();
    expect(DB::table('shipments')->where('id', $pending->id)->value('destination_department'))->toBeNull();

    $this->artisan('logistics:shipments:backfill-scope', ['--force' => true])->assertSuccessful();

    $row = DB::table('shipments')->where('id', $pending->id)->first();
    expect($row->destination_department)->toBe('la_paz')
        ->and($row->origin_department)->toBe('la_paz')
        ->and($row->origin_node_id)->not->toBeNull();

    // El origen ya informado no se pisa: solo se completan sus departamentos.
    $kept = DB::table('shipments')->where('id', $informed->id)->first();
    expect($kept->origin_node_id)->toBe($net['cd_cbba'])
        ->and($kept->origin_department)->toBe('cochabamba')
        ->and($kept->destination_department)->toBe('la_paz');

    // Idempotente: una segunda corrida no cambia nada.
    $this->artisan('logistics:shipments:backfill-scope', ['--force' => true])->assertSuccessful();
    expect(DB::table('shipments')->where('id', $informed->id)->value('origin_node_id'))->toBe($net['cd_cbba']);
});
