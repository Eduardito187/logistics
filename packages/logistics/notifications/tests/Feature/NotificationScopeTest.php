<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Logistics\Notifications\Application\Query\CountUnreadNotifications\CountUnreadNotifications;
use Logistics\Notifications\Application\Query\GetNotification\GetNotification;
use Logistics\Notifications\Application\Query\ListNotifications\ListNotifications;
use Logistics\Notifications\Application\Query\ListNotificationsByShipment\ListNotificationsByShipment;
use Logistics\Notifications\Domain\ValueObject\NotificationStatus;
use Logistics\Notifications\Infrastructure\Persistence\Eloquent\NotificationRecord;
use Logistics\Shared\Application\Query\QueryBus;
use Logistics\Shared\Domain\ValueObject\Uuid;
use Logistics\Shipments\Infrastructure\Persistence\Eloquent\ShipmentRecord;

/**
 * ALCANCE POR ROL de las notificaciones de la app: una notificación es un aviso anclado a un envío y
 * hereda el alcance de ese envío (la misma regla de los pedidos):
 *  - admin / supervisor (y una cuenta sin rol operativo): ven el log completo, también los avisos huérfanos;
 *  - preparador / planificador con ciudad: solo los de envíos con origen o destino en su departamento;
 *  - tienda: solo los de envíos que salen de su nodo o llegan a él;
 *  - chofer: solo los de envíos que le asignaron.
 * La página, `meta.total` y `meta.unread_count` cuentan solo lo visible. El detalle, marcar leída y suprimir
 * fuera de alcance responden 404 (no se revela que la notificación existe). Sin usuario de la app (procesos
 * internos, puerta de canales) no hay restricción.
 *
 * Se omite el middleware de puerta (auth / device / rol); el usuario se fija con actingAs. Contra
 * PostgreSQL (base logistics_test).
 */
beforeEach(function (): void {
    $this->withoutMiddleware();
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

/**
 * Usuario de la app con ese rol y esos vínculos (city, node_id, driver_id…), ya autenticado.
 *
 * @param array<string, mixed> $links
 */
function nscActingAs(Role $role, array $links = []): User
{
    $user = User::factory()->create();
    $user->assignRole($role);
    if ($links !== []) {
        $user->forceFill($links)->save();
    }
    test()->actingAs($user);

    return $user;
}

/** Nodo de red de ese tipo y departamento; devuelve su id. */
function nscNode(string $type, string $department, string $name): string
{
    $id = Uuid::generate()->value;
    DB::table('network_nodes')->insert([
        'id' => $id,
        'code' => 'NSC-'.Str::upper(Str::random(8)),
        'name' => $name,
        'type' => $type,
        'department' => $department,
        'lat' => -17.0,
        'lng' => -65.0,
        'status' => 'active',
    ]);

    return $id;
}

/**
 * Red mínima de dos ciudades: un centro de distribución y una tienda en cada una.
 *
 * @return array<string, string>
 */
function nscNetwork(): array
{
    return [
        'cd_lpz' => nscNode('distribution_center', 'la_paz', 'CD La Paz'),
        'store_lpz' => nscNode('store', 'la_paz', 'Tienda La Paz'),
        'cd_scz' => nscNode('distribution_center', 'santa_cruz', 'CD Santa Cruz'),
        'store_scz' => nscNode('store', 'santa_cruz', 'Tienda Santa Cruz'),
        'store_scz_2' => nscNode('store', 'santa_cruz', 'Tienda Santa Cruz Norte'),
    ];
}

/** Envío que sale del nodo `$origin` hacia el nodo `$destination` (los departamentos se resuelven solos). */
function nscShipment(string $origin, string $destination): ShipmentRecord
{
    $order = 'NSC-'.strtoupper(substr(str_replace('-', '', Uuid::generate()->value), -10));

    return ShipmentRecord::factory()->forNode($destination)->create([
        'external_id' => $order,
        'order_reference' => $order,
        'origin_node_id' => $origin,
    ]);
}

/**
 * Notificación encolada de ese envío, creada hace N horas. Relativa a "ahora" porque «leer todas» recorta
 * `up_to` al reloj real, y en la zona de la app porque `created_at` es una columna `timestamp` sin zona que
 * Laravel escribe así (ver NotificationTimestamps). Las fechas que viajan por la API sí llevan `+00:00`.
 */
function nscNotification(string $shipmentId, int $hoursAgo): NotificationRecord
{
    $createdAt = Carbon::now()->subHours($hoursAgo)->startOfSecond();

    return NotificationRecord::factory()->create([
        'shipment_id' => $shipmentId,
        'status' => NotificationStatus::Queued->value,
        'created_at' => $createdAt,
        'updated_at' => $createdAt,
    ]);
}

/**
 * Ruta de un vehículo de flota con ese chofer y una parada por cada envío.
 *
 * @param list<string> $shipmentIds
 */
function nscRoute(string $driverId, array $shipmentIds): void
{
    $vehicleId = Uuid::generate()->value;
    DB::table('fleet_vehicles')->insert([
        'id' => $vehicleId,
        'plate' => 'NSC-'.strtoupper(Str::random(4)),
        'capacity_kg' => 3000,
        'capacity_m3' => 12,
        'home_base_node_id' => Uuid::generate()->value,
        'status' => 'available',
        'documents' => '[]',
    ]);

    $routeId = Uuid::generate()->value;
    DB::table('execution_driver_routes')->insert([
        'id' => $routeId, 'vehicle_id' => $vehicleId, 'route_date' => '2026-11-14', 'status' => 'PLANNED',
        'driver_id' => $driverId, 'created_at' => now(), 'updated_at' => now(),
    ]);

    foreach (array_values($shipmentIds) as $index => $shipmentId) {
        DB::table('execution_driver_route_stops')->insert([
            'id' => Uuid::generate()->value, 'route_id' => $routeId, 'shipment_id' => $shipmentId,
            'sequence' => $index + 1, 'status' => 'PENDING', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}

/**
 * Escenario base: dos avisos de un envío de La Paz, uno de Santa Cruz y uno huérfano (su envío no existe).
 *
 * @return array{net: array<string, string>, lpz: ShipmentRecord, scz: ShipmentRecord, lpz_old: NotificationRecord, lpz_new: NotificationRecord, scz_one: NotificationRecord, orphan: NotificationRecord}
 */
function nscScenario(): array
{
    $net = nscNetwork();
    $lpz = nscShipment($net['cd_lpz'], $net['store_lpz']);
    $scz = nscShipment($net['cd_scz'], $net['store_scz']);

    return [
        'net' => $net,
        'lpz' => $lpz,
        'scz' => $scz,
        'lpz_old' => nscNotification($lpz->id, 6),
        'scz_one' => nscNotification($scz->id, 5),
        'lpz_new' => nscNotification($lpz->id, 4),
        'orphan' => nscNotification(Uuid::generate()->value, 3),
    ];
}

/**
 * Ids que devuelve el log de la app para el usuario autenticado.
 *
 * @return list<string>
 */
function nscListedIds(string $query = ''): array
{
    /** @var list<string> $ids */
    $ids = collect(test()->getJson('/api/v1/app/notifications/all'.$query)->assertOk()->json('data'))->pluck('id')->all();

    return $ids;
}

it('admin, supervisor y una cuenta sin rol ven el log completo, incluidos los avisos huérfanos', function (): void {
    $s = nscScenario();
    $all = [$s['orphan']->id, $s['lpz_new']->id, $s['scz_one']->id, $s['lpz_old']->id];

    nscActingAs(Role::Admin);
    expect(nscListedIds())->toBe($all);
    $this->getJson('/api/v1/app/notifications/all')
        ->assertJsonPath('meta.total', 4)
        ->assertJsonPath('meta.unread_count', 4);
    $this->getJson("/api/v1/app/notifications/{$s['orphan']->id}")->assertOk();

    nscActingAs(Role::Supervisor);
    expect(nscListedIds())->toBe($all);

    // Sin rol operativo: este servicio no la acota (la autorización por rol es otra capa).
    $this->actingAs(User::factory()->create());
    expect(nscListedIds())->toBe($all);
});

it('el planificador y el preparador con ciudad ven solo los avisos de su ciudad, con total y no leídas coherentes', function (): void {
    $s = nscScenario();

    foreach ([Role::Planificador, Role::Preparador] as $role) {
        nscActingAs($role, ['city' => 'la_paz']);

        expect(nscListedIds())->toBe([$s['lpz_new']->id, $s['lpz_old']->id]);

        // La paginación cuenta solo lo visible.
        $this->getJson('/api/v1/app/notifications/all?page=1&per_page=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $s['lpz_new']->id)
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.total_pages', 2)
            ->assertJsonPath('meta.unread_count', 2);

        $this->getJson('/api/v1/app/notifications/all?page=2&per_page=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $s['lpz_old']->id)
            ->assertJsonPath('meta.next_page', null);

        // El filtro de no leídas también respeta el alcance.
        $this->getJson('/api/v1/app/notifications/all?unread=true')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.unread_count', 2);
    }

    // El de Santa Cruz ve el suyo y no los de La Paz ni el huérfano.
    nscActingAs(Role::Planificador, ['city' => 'santa_cruz']);
    expect(nscListedIds())->toBe([$s['scz_one']->id]);
});

it('un envío entre dos ciudades lo ven las dos, y un preparador sin ciudad no queda restringido', function (): void {
    $net = nscNetwork();
    $crossing = nscShipment($net['cd_lpz'], $net['store_scz']);
    $notification = nscNotification($crossing->id, 2);

    nscActingAs(Role::Preparador, ['city' => 'la_paz']);
    expect(nscListedIds())->toBe([$notification->id]);

    nscActingAs(Role::Preparador, ['city' => 'santa_cruz']);
    expect(nscListedIds())->toBe([$notification->id]);

    nscActingAs(Role::Preparador, ['city' => 'cochabamba']);
    expect(nscListedIds())->toBe([]);

    // Sin ciudad asignada: sin restricción (decisión vigente del alcance de envíos).
    nscActingAs(Role::Preparador);
    expect(nscListedIds())->toBe([$notification->id]);

    // Con el modo estricto, sin ciudad no ve ningún aviso.
    config(['logistics-shipments.scope.strict_without_city' => true]);
    expect(nscListedIds())->toBe([]);
    $this->getJson('/api/v1/app/notifications/all')->assertJsonPath('meta.total', 0)->assertJsonPath('meta.unread_count', 0);
});

it('la tienda ve solo los avisos de envíos que salen de su nodo o llegan a él', function (): void {
    $s = nscScenario();
    $outgoing = nscShipment($s['net']['store_scz'], $s['net']['store_scz_2']);
    $fromStore = nscNotification($outgoing->id, 2);
    // Misma ciudad, otra tienda: no es suyo.
    $foreign = nscShipment($s['net']['cd_scz'], $s['net']['store_scz_2']);
    $foreignNotification = nscNotification($foreign->id, 1);

    nscActingAs(Role::Tienda, ['node_id' => $s['net']['store_scz']]);

    expect(nscListedIds())->toBe([$fromStore->id, $s['scz_one']->id]);
    $this->getJson('/api/v1/app/notifications/all')
        ->assertJsonPath('meta.total', 2)
        ->assertJsonPath('meta.unread_count', 2);
    $this->getJson("/api/v1/app/notifications/{$foreignNotification->id}")->assertNotFound();

    // Una tienda sin nodo asignado no ve ningún aviso.
    nscActingAs(Role::Tienda);
    expect(nscListedIds())->toBe([]);
});

it('el chofer ve solo los avisos de los envíos que le asignaron', function (): void {
    $s = nscScenario();
    $driverId = Uuid::generate()->value;
    nscRoute($driverId, [$s['scz']->id]);
    // Ruta de otro chofer con el envío de La Paz.
    nscRoute(Uuid::generate()->value, [$s['lpz']->id]);

    nscActingAs(Role::Chofer, ['driver_id' => $driverId]);

    expect(nscListedIds())->toBe([$s['scz_one']->id]);
    $this->getJson('/api/v1/app/notifications/all')
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('meta.unread_count', 1);
    $this->getJson("/api/v1/app/notifications/{$s['lpz_new']->id}")->assertNotFound();
});

it('el log por envío sale vacío si el envío está fuera de alcance', function (): void {
    $s = nscScenario();
    nscActingAs(Role::Planificador, ['city' => 'la_paz']);

    $this->getJson('/api/v1/app/notifications?shipment_id='.$s['lpz']->id)
        ->assertOk()
        ->assertJsonCount(2, 'data');

    $this->getJson('/api/v1/app/notifications?shipment_id='.$s['scz']->id)
        ->assertOk()
        ->assertJsonCount(0, 'data');

    // Envío que no existe (aviso huérfano): tampoco se lista para un usuario acotado.
    $this->getJson('/api/v1/app/notifications?shipment_id='.$s['orphan']->shipment_id)
        ->assertOk()
        ->assertJsonCount(0, 'data');

    // La puerta de canales no se acota por rol: su credencial es del canal.
    $this->getJson('/api/v1/channels/shipments/'.$s['scz']->id.'/notifications')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

it('detalle, marcar leída y suprimir fuera de alcance responden 404 y no cambian nada', function (): void {
    $s = nscScenario();
    $user = nscActingAs(Role::Planificador, ['city' => 'la_paz']);

    foreach ([$s['scz_one'], $s['orphan']] as $hidden) {
        $this->getJson("/api/v1/app/notifications/{$hidden->id}")->assertNotFound();
        $this->postJson("/api/v1/app/notifications/{$hidden->id}/read")->assertNotFound();
        $this->postJson("/api/v1/app/notifications/{$hidden->id}/suppress")->assertNotFound();

        expect(DB::table('notification_reads')->where('user_id', (string) $user->id)->where('notification_id', $hidden->id)->exists())->toBeFalse()
            ->and($hidden->refresh()->status)->toBe(NotificationStatus::Queued->value);
    }

    // Mismo 404 que una notificación que no existe.
    $this->postJson('/api/v1/app/notifications/'.Uuid::generate()->value.'/suppress')->assertNotFound();

    // Dentro de su alcance opera normal.
    $this->getJson("/api/v1/app/notifications/{$s['lpz_new']->id}")->assertOk()->assertJsonPath('data.id', $s['lpz_new']->id);
    $this->postJson("/api/v1/app/notifications/{$s['lpz_new']->id}/read")
        ->assertOk()
        ->assertJsonPath('data.unread_count', 1);
    $this->postJson("/api/v1/app/notifications/{$s['lpz_old']->id}/suppress")
        ->assertStatus(202)
        ->assertJsonPath('data.status', 'suppressed');
});

it('«leer todas» deja en cero lo visible y no toca las no leídas de otro usuario', function (): void {
    $s = nscScenario();
    $admin = User::factory()->create();
    $admin->assignRole(Role::Admin);

    nscActingAs(Role::Planificador, ['city' => 'la_paz']);

    // Hasta una fecha (ISO 8601 con offset): queda sin leer solo el aviso visible posterior.
    $this->postJson('/api/v1/app/notifications/read-all', ['up_to' => Carbon::now('UTC')->subHours(5)->format('Y-m-d\TH:i:s').'+00:00'])
        ->assertOk()
        ->assertJsonPath('data.unread_count', 1);

    $this->postJson('/api/v1/app/notifications/read-all')
        ->assertOk()
        ->assertJsonPath('data.unread_count', 0);

    $this->getJson('/api/v1/app/notifications/all?unread=true')
        ->assertOk()
        ->assertJsonCount(0, 'data')
        ->assertJsonPath('meta.total', 0)
        ->assertJsonPath('meta.unread_count', 0);

    // El estado de lectura es por usuario: para el admin siguen las cuatro sin leer.
    $this->actingAs($admin);
    $this->getJson('/api/v1/app/notifications/all')
        ->assertOk()
        ->assertJsonPath('meta.total', 4)
        ->assertJsonPath('meta.unread_count', 4);
});

it('sin usuario de la app (procesos internos) no hay restricción', function (): void {
    $s = nscScenario();
    $bus = app(QueryBus::class);

    // Consultas internas sin actor de alcance.
    expect($bus->ask(new ListNotifications(50))->total)->toBe(4)
        ->and($bus->ask(new GetNotification($s['orphan']->id)))->not->toBeNull()
        ->and($bus->ask(new ListNotificationsByShipment($s['scz']->id)))->toHaveCount(1)
        ->and($bus->ask(new CountUnreadNotifications('usuario-interno')))->toBe(4);

    // Petición sin usuario autenticado (el middleware de la puerta está omitido): log completo.
    $this->getJson('/api/v1/app/notifications/all')
        ->assertOk()
        ->assertJsonCount(4, 'data')
        ->assertJsonPath('meta.total', 4)
        ->assertJsonPath('meta.unread_count', null);
    $this->getJson("/api/v1/app/notifications/{$s['scz_one']->id}")->assertOk();
});
