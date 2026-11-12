<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Http\Middleware\EnsureDeviceToken;
use App\Http\Middleware\IdempotentRequests;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Logistics\Shared\Domain\ValueObject\Uuid;
use Logistics\Shipments\Infrastructure\Persistence\Eloquent\ShipmentRecord;

/** Fecha prometida base de los escenarios del resumen. */
const SHIPMENT_SUMMARY_KPIS_DAY = '2031-03-10';

/** Franja de entrega de mañana usada en los escenarios (inicio y fin). */
const SHIPMENT_SUMMARY_KPIS_WINDOW_START = '09:00';
const SHIPMENT_SUMMARY_KPIS_WINDOW_END = '13:00';

/**
 * Auditoría app #16 / #22 / #26 + KPIs del día, contra PostgreSQL (base logistics_test):
 *  - GET /app/shipments/summary (agregados por estado, alcance por tienda);
 *  - GET /app/shipments con varios estados, `include=count_by_status` y filtro `delivery_mode`;
 *  - `delivery_mode`, `address_parts` y `short_address` en cada envío;
 *  - GET /app/kpis/daily (entregadas, fallidas, pendientes, a tiempo, primer intento, motivos, alcance).
 */
beforeEach(function (): void {
    $this->withoutMiddleware([EnsureDeviceToken::class, IdempotentRequests::class]);

    // Sin caché corta en los tests: cada request ve la base tal como la dejó el test.
    config(['logistics-shipments.read_cache.ttl' => 0]);

    $user = User::factory()->create();
    $user->assignRole(Role::Supervisor);
    $this->actingAs($user, 'sanctum');
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

/** Inserta un nodo de red del tipo dado y devuelve su id. */
function summaryTestNode(string $type): string
{
    $id = Uuid::generate()->value;
    DB::table('network_nodes')->insert([
        'id' => $id,
        'code' => 'T-'.strtoupper(substr($id, -8)),
        'name' => 'Nodo de prueba '.$type,
        'type' => $type,
        'lat' => -17.78,
        'lng' => -63.18,
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $id;
}

/** Inserta un camión y devuelve su id. */
function summaryTestVehicle(): string
{
    $id = Uuid::generate()->value;
    DB::table('fleet_vehicles')->insert([
        'id' => $id,
        'plate' => 'KPI-'.strtoupper(substr($id, -4)),
        'capacity_kg' => 3000,
        'capacity_m3' => 12,
        'home_base_node_id' => Uuid::generate()->value,
        'status' => 'available',
        'documents' => '[]',
    ]);

    return $id;
}

/** Inserta la ruta del día de un camión y devuelve su id. */
function summaryTestRoute(string $vehicleId, string $date): string
{
    $id = Uuid::generate()->value;
    DB::table('execution_driver_routes')->insert([
        'id' => $id, 'vehicle_id' => $vehicleId, 'route_date' => $date, 'status' => 'IN_PROGRESS',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    return $id;
}

/** Inserta una parada de ruta. */
function summaryTestStop(string $routeId, string $shipmentId, int $sequence, string $status, ?string $settledAt = null): void
{
    DB::table('execution_driver_route_stops')->insert([
        'id' => Uuid::generate()->value, 'route_id' => $routeId, 'shipment_id' => $shipmentId,
        'sequence' => $sequence, 'status' => $status, 'settled_at' => $settledAt,
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

/** Inserta la entrega en ejecución de un envío. */
function summaryTestDelivery(string $shipmentId, string $vehicleId, string $status, ?string $podAt = null, ?string $failureReason = null): void
{
    DB::table('execution_deliveries')->insert([
        'id' => Uuid::generate()->value, 'shipment_id' => $shipmentId, 'vehicle_id' => $vehicleId,
        'status' => $status, 'pod_captured_at' => $podAt, 'failure_reason' => $failureReason,
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

// ── #16 resumen del tablero ────────────────────────────────────────────────
it('resume los envíos por estado con agregados SQL, acotado por fecha y nodo', function (): void {
    $node = Uuid::generate()->value;
    $planned = ShipmentRecord::factory()->forNode($node)->withDeliverySlot(SHIPMENT_SUMMARY_KPIS_DAY, SHIPMENT_SUMMARY_KPIS_WINDOW_START, SHIPMENT_SUMMARY_KPIS_WINDOW_END)->create(['status' => 'PLANNED']);
    ShipmentRecord::factory()->forNode($node)->withDeliverySlot(SHIPMENT_SUMMARY_KPIS_DAY, SHIPMENT_SUMMARY_KPIS_WINDOW_START, SHIPMENT_SUMMARY_KPIS_WINDOW_END)->create(['status' => 'PLANNED']);
    ShipmentRecord::factory()->forNode($node)->withDeliverySlot(SHIPMENT_SUMMARY_KPIS_DAY, '14:00', '18:00')->create(['status' => 'RECEIVED']);
    // Otro día: fuera del resumen.
    ShipmentRecord::factory()->forNode($node)->withDeliverySlot('2031-03-11', SHIPMENT_SUMMARY_KPIS_WINDOW_START, SHIPMENT_SUMMARY_KPIS_WINDOW_END)->create(['status' => 'DELIVERED']);

    summaryTestDelivery($planned->id, Uuid::generate()->value, 'LOADED');

    $data = $this->getJson("/api/v1/app/shipments/summary?date=2031-03-10&destination_node_id={$node}")
        ->assertOk()
        ->json('data');

    expect($data['by_status'])->toMatchArray([
        'RECEIVED' => 1, 'PLANNED' => 2, 'IN_EXECUTION' => 0, 'DELIVERED' => 0, 'FAILED' => 0, 'CANCELLED' => 0,
    ])
        ->and($data['total'])->toBe(3)
        ->and($data['by_execution_status'])->toBe(['LOADED' => 1])
        ->and($data['date_from'])->toBe(SHIPMENT_SUMMARY_KPIS_DAY)
        ->and($data['date_to'])->toBe(SHIPMENT_SUMMARY_KPIS_DAY)
        ->and($data)->toHaveKeys(['active_plans', 'open_alerts', 'units_total', 'generated_at']);

    // Rango de fechas: incluye el día siguiente.
    $range = $this->getJson("/api/v1/app/shipments/summary?date_from=2031-03-10&date_to=2031-03-11&destination_node_id={$node}")
        ->assertOk()
        ->json('data');

    expect($range['total'])->toBe(4)
        ->and($range['by_status']['DELIVERED'])->toBe(1);

    $this->getJson('/api/v1/app/shipments/summary?date_from=2031-03-11&date_to=2031-03-10')->assertStatus(422);
    $this->getJson('/api/v1/app/shipments/summary?date=10/03/2031')->assertStatus(422);
});

it('acota el resumen de una tienda a su propio nodo (ignora el filtro del cliente)', function (): void {
    $ownNode = Uuid::generate()->value;
    $otherNode = Uuid::generate()->value;
    ShipmentRecord::factory()->forNode($ownNode)->withDeliverySlot('2031-03-12', SHIPMENT_SUMMARY_KPIS_WINDOW_START, SHIPMENT_SUMMARY_KPIS_WINDOW_END)->create(['status' => 'PLANNED']);
    ShipmentRecord::factory()->forNode($otherNode)->withDeliverySlot('2031-03-12', SHIPMENT_SUMMARY_KPIS_WINDOW_START, SHIPMENT_SUMMARY_KPIS_WINDOW_END)->create(['status' => 'PLANNED']);

    $store = User::factory()->create();
    $store->assignRole(Role::Tienda);
    $store->forceFill(['node_id' => $ownNode])->save();
    $this->actingAs($store, 'sanctum');

    $this->getJson("/api/v1/app/shipments/summary?date=2031-03-12&destination_node_id={$otherNode}")
        ->assertOk()
        ->assertJsonPath('data.total', 1)
        ->assertJsonPath('data.by_status.PLANNED', 1);
});

// ── #16 listado: varios estados + conteo por estado ────────────────────────
it('filtra por varios estados y agrega meta.count_by_status a pedido', function (): void {
    $node = Uuid::generate()->value;
    ShipmentRecord::factory()->forNode($node)->count(2)->create(['status' => 'PLANNED']);
    ShipmentRecord::factory()->forNode($node)->create(['status' => 'RECEIVED']);
    ShipmentRecord::factory()->forNode($node)->create(['status' => 'DELIVERED']);

    $response = $this->getJson("/api/v1/app/shipments?destination_node_id={$node}&status[]=planned&status[]=RECEIVED&include=count_by_status")
        ->assertOk()
        ->assertJsonCount(3, 'data');

    expect($response->json('meta.count_by_status'))->toMatchArray(['PLANNED' => 2, 'RECEIVED' => 1, 'DELIVERED' => 1, 'FAILED' => 0]);

    // Variante separada por coma, y con paginación (el conteo convive con el meta de la página).
    $this->getJson("/api/v1/app/shipments?destination_node_id={$node}&status=planned,received")
        ->assertOk()
        ->assertJsonCount(3, 'data')
        ->assertJsonMissingPath('meta');

    $this->getJson("/api/v1/app/shipments?destination_node_id={$node}&status[]=PLANNED&status[]=RECEIVED&page=1&per_page=2&include=count_by_status")
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('meta.total', 3)
        ->assertJsonPath('meta.count_by_status.DELIVERED', 1);

    // Un estado único sigue el camino histórico; un desconocido dentro de la lista → 422.
    $this->getJson("/api/v1/app/shipments?destination_node_id={$node}&status[]=planned")
        ->assertOk()
        ->assertJsonCount(2, 'data');
    $this->getJson("/api/v1/app/shipments?destination_node_id={$node}&status[]=planned&status[]=archivado")
        ->assertStatus(422);
});

// ── #22 modalidad de entrega + #26 dirección estructurada ──────────────────
it('deriva delivery_mode del tipo del nodo destino y filtra por él', function (): void {
    $store = summaryTestNode('store');
    $dc = summaryTestNode('distribution_center');
    $date = '2031-04-15';

    $pickup = ShipmentRecord::factory()->forNode($store)->withDeliverySlot($date, SHIPMENT_SUMMARY_KPIS_WINDOW_START, SHIPMENT_SUMMARY_KPIS_WINDOW_END)->create([
        'address_text' => 'Av. 6 de Agosto 2170, Sopocachi, La Paz',
        'address_reference' => 'Edificio azul',
    ]);
    $transfer = ShipmentRecord::factory()->forNode($dc)->withDeliverySlot($date, SHIPMENT_SUMMARY_KPIS_WINDOW_START, SHIPMENT_SUMMARY_KPIS_WINDOW_END)->create();
    $home = ShipmentRecord::factory()->withDeliverySlot($date, SHIPMENT_SUMMARY_KPIS_WINDOW_START, SHIPMENT_SUMMARY_KPIS_WINDOW_END)->create();
    // Reposición CD → tienda (traspaso interno): va a la MISMA tienda pero NO es recojo del cliente.
    $replenishment = ShipmentRecord::factory()->transfer()->forNode($store)->withDeliverySlot($date, SHIPMENT_SUMMARY_KPIS_WINDOW_START, SHIPMENT_SUMMARY_KPIS_WINDOW_END)->create();

    $ids = fn (string $mode): array => collect(
        $this->getJson("/api/v1/app/shipments?delivery_date_from={$date}&delivery_date_to={$date}&delivery_mode={$mode}")
            ->assertOk()
            ->json('data'),
    )->pluck('delivery_mode', 'id')->all();

    $homeDelivery = $ids('HOME_DELIVERY');
    ksort($homeDelivery);
    $expectedHome = [$transfer->id => 'home_delivery', $home->id => 'home_delivery'];
    ksort($expectedHome);

    expect($ids('store_pickup'))->toBe([$pickup->id => 'store_pickup'])
        ->and($homeDelivery)->toBe($expectedHome)
        ->and($ids('transfer'))->toBe([$replenishment->id => 'transfer']);

    $this->getJson("/api/v1/app/shipments/{$replenishment->id}")
        ->assertOk()
        ->assertJsonPath('data.delivery_mode', 'transfer');

    $this->getJson('/api/v1/app/shipments?delivery_mode=courier')->assertStatus(422);

    // El detalle trae la modalidad y la dirección estructurada.
    $this->getJson("/api/v1/app/shipments/{$pickup->id}")
        ->assertOk()
        ->assertJsonPath('data.delivery_mode', 'store_pickup')
        ->assertJsonPath('data.short_address', 'Av. 6 de Agosto 2170, Sopocachi')
        ->assertJsonPath('data.address_parts', [
            'street' => 'Av. 6 de Agosto',
            'number' => '2170',
            'neighborhood' => 'Sopocachi',
            'reference' => 'Edificio azul',
            'city' => 'La Paz',
        ]);

    // El resumen también filtra por modalidad.
    $this->getJson("/api/v1/app/shipments/summary?date={$date}&delivery_mode=store_pickup")
        ->assertOk()
        ->assertJsonPath('data.total', 1);
    $this->getJson("/api/v1/app/shipments/summary?date={$date}&delivery_mode=transfer")
        ->assertOk()
        ->assertJsonPath('data.total', 1);
});

// ── KPIs del día ───────────────────────────────────────────────────────────
it('calcula los KPIs del día con SQL: entregadas, fallidas, pendientes, a tiempo y primer intento', function (): void {
    $vehicle = summaryTestVehicle();
    $date = '2031-02-10';
    $route = summaryTestRoute($vehicle, $date);

    // A: entregada a tiempo (cierre 12:00 La Paz, ventana hasta 13:00), primer intento.
    $onTime = ShipmentRecord::factory()->withDeliverySlot($date, SHIPMENT_SUMMARY_KPIS_WINDOW_START, SHIPMENT_SUMMARY_KPIS_WINDOW_END)->create(['status' => 'DELIVERED'])->id;
    summaryTestStop($route, $onTime, 1, 'COMPLETED', '2031-02-10T12:00:00-04:00');

    // B: fallida (cliente ausente).
    $failed = ShipmentRecord::factory()->withDeliverySlot($date, SHIPMENT_SUMMARY_KPIS_WINDOW_START, SHIPMENT_SUMMARY_KPIS_WINDOW_END)->create(['status' => 'FAILED'])->id;
    summaryTestStop($route, $failed, 2, 'FAILED', '2031-02-10T11:00:00-04:00');
    summaryTestDelivery($failed, $vehicle, 'FAILED', null, 'CUSTOMER_ABSENT');

    // C: pendiente.
    $pending = ShipmentRecord::factory()->withDeliverySlot($date, '14:00', '18:00')->create(['status' => 'IN_EXECUTION'])->id;
    summaryTestStop($route, $pending, 3, 'PENDING');

    // D: entregada TARDE (POD 15:00, ventana hasta 13:00), parada aún sin cerrar, y con un intento
    //    fallido el día anterior (no es primer intento).
    $late = ShipmentRecord::factory()->withDeliverySlot($date, SHIPMENT_SUMMARY_KPIS_WINDOW_START, SHIPMENT_SUMMARY_KPIS_WINDOW_END)->create(['status' => 'DELIVERED'])->id;
    summaryTestStop($route, $late, 4, 'PENDING');
    summaryTestDelivery($late, $vehicle, 'DELIVERED', '2031-02-10T15:00:00-04:00');
    summaryTestStop(summaryTestRoute($vehicle, '2031-02-09'), $late, 1, 'FAILED', '2031-02-09T16:00:00-04:00');

    $data = $this->getJson("/api/v1/app/kpis/daily?date={$date}&vehicle_id={$vehicle}")
        ->assertOk()
        ->json('data');

    expect($data)->toMatchArray([
        'date' => $date,
        'scope' => 'fleet',
        'deliveries_total' => 4,
        'delivered' => 2,
        'failed' => 1,
        'rescheduled' => 0,
        'pending' => 1,
        'failed_by_reason' => [['reason' => 'CUSTOMER_ABSENT', 'count' => 1]],
    ])
        ->and($data['on_time_rate'])->toEqual(0.5)
        ->and($data['first_attempt_success_rate'])->toEqual(round(1 / 3, 4))
        ->and($data)->toHaveKeys(['avg_stop_minutes', 'km', 'generated_at']);

    // Sin la columna `arrived_at` (la agrega execution), el promedio por parada queda null.
    if (!Schema::hasColumn('execution_driver_route_stops', 'arrived_at')) {
        expect($data['avg_stop_minutes'])->toBeNull();
    }

    // Otro día: sin paradas → cada indicador en cero y tasas null.
    $this->getJson("/api/v1/app/kpis/daily?date=2031-02-11&vehicle_id={$vehicle}")
        ->assertOk()
        ->assertJsonPath('data.deliveries_total', 0)
        ->assertJsonPath('data.on_time_rate', null)
        ->assertJsonPath('data.first_attempt_success_rate', null);
});

it('acota los KPIs del chofer a lo suyo e ignora los filtros; rechaza roles sin acceso', function (): void {
    $driverId = Uuid::generate()->value;
    $mine = summaryTestVehicle();
    $other = summaryTestVehicle();
    $date = '2031-02-20';

    $myRoute = summaryTestRoute($mine, $date);
    DB::table('execution_driver_routes')->where('id', $myRoute)->update(['driver_id' => $driverId]);
    summaryTestStop($myRoute, ShipmentRecord::factory()->create()->id, 1, 'COMPLETED', '2031-02-20T10:00:00-04:00');

    $otherRoute = summaryTestRoute($other, $date);
    summaryTestStop($otherRoute, ShipmentRecord::factory()->create()->id, 1, 'PENDING');
    summaryTestStop($otherRoute, ShipmentRecord::factory()->create()->id, 2, 'PENDING');

    $driver = User::factory()->create();
    $driver->assignRole(Role::Chofer);
    $driver->forceFill(['driver_id' => $driverId])->save();
    $this->actingAs($driver, 'sanctum');

    $this->getJson("/api/v1/app/kpis/daily?date={$date}&vehicle_id={$other}")
        ->assertOk()
        ->assertJsonPath('data.scope', 'driver')
        ->assertJsonPath('data.deliveries_total', 1)
        ->assertJsonPath('data.delivered', 1);

    // Chofer sin chofer vinculado: KPIs en cero (nunca datos ajenos).
    $unlinked = User::factory()->create();
    $unlinked->assignRole(Role::Chofer);
    $this->actingAs($unlinked, 'sanctum');

    $this->getJson("/api/v1/app/kpis/daily?date={$date}")
        ->assertOk()
        ->assertJsonPath('data.deliveries_total', 0);

    // Preparador: sin acceso.
    $picker = User::factory()->create();
    $picker->assignRole(Role::Preparador);
    $this->actingAs($picker, 'sanctum');

    $this->getJson("/api/v1/app/kpis/daily?date={$date}")->assertStatus(403);
});
