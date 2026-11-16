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
 * Lo que el planificador hace con los envíos desde la app:
 *  - reprograma pedidos PUNTUALES de su ciudad (403 `out_of_scope_city` fuera de ella); la reprogramación
 *    masiva por camión o por ciudad sigue siendo solo de admin (403 `insufficient_role`), con el mismo
 *    criterio en la vista previa;
 *  - filtra el listado por CÓDIGO de departamento (`city=santa_cruz`), también en el conteo por estado;
 *  - ve en la lista y en la ficha qué evidencia quedó de una entrega (`delivery_evidence`).
 * Contra PostgreSQL (base logistics_test).
 */
beforeEach(function (): void {
    $this->withoutMiddleware([EnsureDeviceToken::class, IdempotentRequests::class]);
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

/**
 * Usuario de la app con ese rol y esos vínculos (city…), ya autenticado.
 *
 * @param array<string, mixed> $links
 */
function psaActingAs(Role $role, array $links = []): User
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
function psaNode(string $type, string $department, string $name): string
{
    $id = Uuid::generate()->value;
    DB::table('network_nodes')->insert([
        'id' => $id,
        'code' => 'PSA-'.Str::upper(Str::random(8)),
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
function psaNetwork(): array
{
    return [
        'cd_scz' => psaNode('distribution_center', 'santa_cruz', 'CD Santa Cruz'),
        'store_scz' => psaNode('store', 'santa_cruz', 'Tienda Santa Cruz'),
        'cd_lpz' => psaNode('distribution_center', 'la_paz', 'CD La Paz'),
        'store_lpz' => psaNode('store', 'la_paz', 'Tienda La Paz'),
    ];
}

/**
 * Envío que sale del nodo `$origin` hacia el nodo `$destination` (los departamentos se resuelven solos).
 *
 * @param array<string, mixed> $attributes
 */
function psaShipment(string $origin, string $destination, array $attributes = []): ShipmentRecord
{
    $order = 'PSA-'.strtoupper(substr(str_replace('-', '', Uuid::generate()->value), -10));

    return ShipmentRecord::factory()->forNode($destination)->create([
        'external_id' => $order,
        'order_reference' => $order,
        'origin_node_id' => $origin,
        'status' => 'PLANNED',
        'delivery_date' => '2026-12-01',
        'delivery_window_start' => '09:00',
        'delivery_window_end' => '12:00',
        ...$attributes,
    ]);
}

/**
 * Cuerpo de la reprogramación con el alcance dado.
 *
 * @param  array<string, mixed> $scope
 * @return array<string, mixed>
 */
function psaReschedule(array $scope): array
{
    return [
        'scope' => $scope,
        'new_date' => '2027-01-15',
        'window_start' => '14:00',
        'window_end' => '18:00',
        'reason' => 'El cliente pidió otro día',
    ];
}

/**
 * Entrega en ejecución del envío (DELIVERED), con las columnas de la prueba de entrega dadas.
 *
 * @param array<string, mixed> $pod
 */
function psaDelivery(string $shipmentId, array $pod = []): void
{
    DB::table('execution_deliveries')->insert([
        'id' => (string) Str::uuid7(),
        'shipment_id' => $shipmentId,
        'vehicle_id' => (string) Str::uuid7(),
        'status' => 'DELIVERED',
        ...$pod,
    ]);
}

/** Evidencia capturada del envío de ese tipo (`signature`, `pod`, `id_document`, `damage`). */
function psaEvidence(string $shipmentId, string $kind): void
{
    DB::table('execution_delivery_evidence')->insert([
        'id' => (string) Str::uuid7(),
        'shipment_id' => $shipmentId,
        'kind' => $kind,
        'storage_disk' => 'evidence',
        'storage_path' => 'tests/'.$kind.'-'.Str::random(12).'.jpg',
        'sha256' => hash('sha256', $kind.Str::random(8)),
        'captured_at' => now(),
    ]);
}

it('el planificador reprograma pedidos puntuales de su ciudad, uno o varios', function (): void {
    $net = psaNetwork();
    $first = psaShipment($net['cd_scz'], $net['store_scz']);
    $second = psaShipment($net['cd_scz'], $net['store_scz']);

    psaActingAs(Role::Planificador, ['city' => 'santa_cruz']);

    test()->postJson('/api/v1/app/shipments/reschedule/preview', psaReschedule(['shipment_ids' => [$first->id]]))
        ->assertOk()
        ->assertJsonPath('data.applied', false)
        ->assertJsonPath('data.count', 1);

    test()->postJson('/api/v1/app/shipments/reschedule', psaReschedule(['shipment_ids' => [$first->id, $second->id]]))
        ->assertOk()
        ->assertJsonPath('data.applied', true)
        ->assertJsonPath('data.count', 2)
        ->assertJsonPath('data.failed', []);

    foreach ([$first->id, $second->id] as $id) {
        expect((string) ShipmentRecord::query()->findOrFail($id)->delivery_date)->toStartWith('2027-01-15');
    }
});

it('responde 403 out_of_scope_city y no reprograma nada si un pedido es de otra ciudad', function (): void {
    $net = psaNetwork();
    $local = psaShipment($net['cd_scz'], $net['store_scz']);
    $foreign = psaShipment($net['cd_lpz'], $net['store_lpz']);

    psaActingAs(Role::Planificador, ['city' => 'santa_cruz']);

    $body = psaReschedule(['shipment_ids' => [$local->id, $foreign->id]]);

    foreach (['/api/v1/app/shipments/reschedule/preview', '/api/v1/app/shipments/reschedule'] as $uri) {
        $denied = test()->postJson($uri, $body)
            ->assertStatus(403)
            ->assertJsonPath('status', 403)
            ->assertJsonPath('code', 'out_of_scope_city')
            ->assertJsonPath('error.code', 'out_of_scope_city');

        expect($denied->json('detail'))->toBeString()->not->toBe('')
            ->and($denied->json('error.message'))->toBe($denied->json('detail'));
    }

    // Ni el pedido propio se movió: el lote se rechaza entero.
    expect((string) ShipmentRecord::query()->findOrFail($local->id)->delivery_date)->toStartWith('2026-12-01')
        ->and((string) ShipmentRecord::query()->findOrFail($foreign->id)->delivery_date)->toStartWith('2026-12-01');
});

it('la reprogramación por camión o por ciudad no es del planificador, ni en la vista previa', function (): void {
    $net = psaNetwork();
    $shipment = psaShipment($net['cd_scz'], $net['store_scz']);
    $vehicleId = (string) Str::uuid7();
    DB::table('execution_deliveries')->insert(['id' => (string) Str::uuid7(), 'shipment_id' => $shipment->id, 'vehicle_id' => $vehicleId, 'status' => 'LOADED']);

    psaActingAs(Role::Planificador, ['city' => 'santa_cruz']);

    foreach ([['vehicle_id' => $vehicleId], ['city' => 'santa_cruz']] as $scope) {
        foreach (['/api/v1/app/shipments/reschedule/preview', '/api/v1/app/shipments/reschedule'] as $uri) {
            $denied = test()->postJson($uri, psaReschedule($scope))
                ->assertStatus(403)
                ->assertJsonPath('code', 'insufficient_role')
                ->assertJsonPath('error.code', 'insufficient_role');

            expect($denied->json('detail'))->toBeString()->not->toBe('')
                ->and($denied->json('error.message'))->toBe($denied->json('detail'));
        }
    }

    expect((string) ShipmentRecord::query()->findOrFail($shipment->id)->delivery_date)->toStartWith('2026-12-01');
});

it('el admin sigue reprogramando por camión y pedidos puntuales de cualquier ciudad', function (): void {
    $net = psaNetwork();
    $onTruck = psaShipment($net['cd_scz'], $net['store_scz']);
    $elsewhere = psaShipment($net['cd_lpz'], $net['store_lpz']);
    $vehicleId = (string) Str::uuid7();
    DB::table('execution_deliveries')->insert(['id' => (string) Str::uuid7(), 'shipment_id' => $onTruck->id, 'vehicle_id' => $vehicleId, 'status' => 'LOADED']);

    psaActingAs(Role::Admin);

    test()->postJson('/api/v1/app/shipments/reschedule', psaReschedule(['vehicle_id' => $vehicleId]))
        ->assertOk()
        ->assertJsonPath('data.applied', true)
        ->assertJsonPath('data.count', 1);

    test()->postJson('/api/v1/app/shipments/reschedule', psaReschedule(['shipment_ids' => [$elsewhere->id]]))
        ->assertOk()
        ->assertJsonPath('data.applied', true)
        ->assertJsonPath('data.count', 1);

    expect((string) ShipmentRecord::query()->findOrFail($onTruck->id)->delivery_date)->toStartWith('2027-01-15')
        ->and((string) ShipmentRecord::query()->findOrFail($elsewhere->id)->delivery_date)->toStartWith('2027-01-15');
});

it('el supervisor conserva la vista previa masiva y sigue sin poder aplicar', function (): void {
    $net = psaNetwork();
    $shipment = psaShipment($net['cd_scz'], $net['store_scz']);
    $vehicleId = (string) Str::uuid7();
    DB::table('execution_deliveries')->insert(['id' => (string) Str::uuid7(), 'shipment_id' => $shipment->id, 'vehicle_id' => $vehicleId, 'status' => 'LOADED']);

    psaActingAs(Role::Supervisor);

    test()->postJson('/api/v1/app/shipments/reschedule/preview', psaReschedule(['vehicle_id' => $vehicleId]))
        ->assertOk()
        ->assertJsonPath('data.count', 1);

    test()->postJson('/api/v1/app/shipments/reschedule', psaReschedule(['shipment_ids' => [$shipment->id]]))
        ->assertStatus(403);
});

it('el listado filtra por código de departamento de destino, también en el conteo por estado', function (): void {
    $net = psaNetwork();
    $toSantaCruz = psaShipment($net['cd_scz'], $net['store_scz']);
    $toLaPaz = psaShipment($net['cd_scz'], $net['store_lpz']);

    psaActingAs(Role::Admin);

    $response = test()->getJson('/api/v1/app/shipments?city=santa_cruz&include=count_by_status')->assertOk();
    $ids = collect($response->json('data'))->pluck('id')->all();

    expect($ids)->toContain($toSantaCruz->id)
        ->and($ids)->not->toContain($toLaPaz->id)
        ->and(collect($response->json('data'))->pluck('destination_department')->unique()->all())->toBe(['santa_cruz'])
        // El conteo por estado usa el mismo filtro: cuenta lo que se ve en la lista.
        ->and($response->json('meta.count_by_status.PLANNED'))->toBe(collect($response->json('data'))->where('status', 'PLANNED')->count());

    $laPaz = collect(test()->getJson('/api/v1/app/shipments?city=la_paz')->assertOk()->json('data'))->pluck('id')->all();

    expect($laPaz)->toContain($toLaPaz->id)
        ->and($laPaz)->not->toContain($toSantaCruz->id);
});

it('un texto libre en city se sigue comparando contra la ciudad o la dirección', function (): void {
    $net = psaNetwork();
    $match = psaShipment($net['cd_scz'], $net['store_scz'], ['address_text' => 'Av. Banzer 123, Villa Psaunica']);
    $other = psaShipment($net['cd_scz'], $net['store_scz'], ['address_text' => 'Calle Sucre 45, Otro Barrio']);
    // Sin ciudad resuelta, el respaldo es el texto de la dirección.
    DB::table('shipments')->whereIn('id', [$match->id, $other->id])->update(['city' => null]);

    psaActingAs(Role::Admin);

    $ids = collect(test()->getJson('/api/v1/app/shipments?city=Villa%20Psaunica')->assertOk()->json('data'))->pluck('id')->all();

    expect($ids)->toContain($match->id)
        ->and($ids)->not->toContain($other->id);
});

it('la lista trae delivery_evidence: null sin entregar y la evidencia que quedó en los entregados', function (): void {
    $net = psaNetwork();
    $pending = psaShipment($net['cd_scz'], $net['store_scz']);
    $signed = psaShipment($net['cd_scz'], $net['store_scz'], ['status' => 'DELIVERED']);
    $photographed = psaShipment($net['cd_scz'], $net['store_scz'], ['status' => 'DELIVERED']);
    $bare = psaShipment($net['cd_scz'], $net['store_scz'], ['status' => 'DELIVERED']);

    // Firma + documento capturados como evidencia; una foto de daños no es evidencia de la entrega.
    psaDelivery($signed->id, ['pod_confirmation' => 'FIRMA-DIGITAL']);
    psaEvidence($signed->id, 'signature');
    psaEvidence($signed->id, 'id_document');
    psaEvidence($signed->id, 'damage');
    // Foto ligada a la prueba de entrega (lista de rutas) sin evidencia capturada aparte.
    psaDelivery($photographed->id, ['pod_photo_paths' => json_encode(['pod/2026/11/foto-1.jpg'])]);
    // Entregado sin ninguna evidencia: los tres indicadores en false (no null).
    psaDelivery($bare->id);

    psaActingAs(Role::Admin);

    $items = collect(test()->getJson('/api/v1/app/shipments?city=santa_cruz&per_page=100')->assertOk()->json('data'))->keyBy('id');

    expect($items[$pending->id])->toHaveKey('delivery_evidence')
        ->and($items[$pending->id]['delivery_evidence'])->toBeNull()
        ->and($items[$signed->id]['delivery_evidence'])->toBe(['signature' => true, 'photo' => false, 'document' => true, 'with_shortage' => false, 'missing_count' => null])
        ->and($items[$photographed->id]['delivery_evidence'])->toBe(['signature' => false, 'photo' => true, 'document' => false, 'with_shortage' => false, 'missing_count' => null])
        ->and($items[$bare->id]['delivery_evidence'])->toBe(['signature' => false, 'photo' => false, 'document' => false, 'with_shortage' => false, 'missing_count' => null]);
});

it('la ficha del envío trae delivery_evidence con el mismo criterio', function (): void {
    $net = psaNetwork();
    $pending = psaShipment($net['cd_scz'], $net['store_scz']);
    $delivered = psaShipment($net['cd_scz'], $net['store_scz'], ['status' => 'DELIVERED']);
    psaDelivery($delivered->id, ['pod_photo_path' => 'pod/2026/11/principal.jpg']);
    psaEvidence($delivered->id, 'pod');
    psaEvidence($delivered->id, 'signature');

    psaActingAs(Role::Planificador, ['city' => 'santa_cruz']);

    test()->getJson('/api/v1/app/shipments/'.$delivered->id)
        ->assertOk()
        ->assertJsonPath('data.delivery_evidence', ['signature' => true, 'photo' => true, 'document' => false, 'with_shortage' => false, 'missing_count' => null]);

    $response = test()->getJson('/api/v1/app/shipments/'.$pending->id)->assertOk();

    expect($response->json('data'))->toHaveKey('delivery_evidence')
        ->and($response->json('data.delivery_evidence'))->toBeNull();
});

it('el planificador recibe los KPIs del día como la oficina', function (): void {
    psaActingAs(Role::Planificador, ['city' => 'santa_cruz']);

    test()->getJson('/api/v1/app/kpis/daily')->assertOk()->assertJsonStructure(['data']);
});
