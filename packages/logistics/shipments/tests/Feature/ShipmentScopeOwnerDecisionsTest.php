<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Http\Middleware\EnsureDeviceToken;
use App\Http\Middleware\IdempotentRequests;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Logistics\Shared\Domain\ValueObject\Uuid;
use Logistics\Shipments\Infrastructure\Persistence\Eloquent\ShipmentRecord;

/**
 * Tres decisiones del dueño sobre el alcance por ciudad y por rol:
 *
 *  1. la ingesta ACEPTA el origen del pedido (`origin_node_id` / `origin_node_code`); si viene se valida y se
 *     guarda, si no se deriva como siempre;
 *  2. en la consulta abierta, buscar por NOMBRE o DOCUMENTO del cliente es solo de oficina y tienda; el resto
 *     consulta cualquier pedido por código, referencia o etiqueta;
 *  3. un preparador SIN ciudad sigue abierto por defecto y, con `scope.strict_without_city`, no lista nada y
 *     sus escrituras responden 403 `out_of_scope_city`.
 *
 * Contra PostgreSQL (base logistics_test).
 */
beforeEach(function (): void {
    $this->withoutMiddleware([EnsureDeviceToken::class, IdempotentRequests::class]);
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

/** Usuario de la app con ese rol y esos vínculos, ya autenticado. */
function sodActingAs(Role $role, array $links = []): User
{
    $user = User::factory()->create();
    $user->assignRole($role);
    if ($links !== []) {
        $user->forceFill($links)->save();
    }
    test()->actingAs($user, 'sanctum');

    return $user;
}

/**
 * Nodo de red de ese tipo y departamento.
 *
 * @return array{id: string, code: string}
 */
function sodNode(string $type, string $department): array
{
    $id = Uuid::generate()->value;
    $code = 'SOD-'.Str::upper(Str::random(8));
    DB::table('network_nodes')->insert([
        'id' => $id,
        'code' => $code,
        'name' => 'Nodo '.$code,
        'type' => $type,
        'department' => $department,
        'lat' => -17.0,
        'lng' => -65.0,
        'status' => 'active',
    ]);

    return ['id' => $id, 'code' => $code];
}

/** Código único por test. */
function sodCode(string $prefix): string
{
    return $prefix.'-'.strtoupper(substr(str_replace('-', '', Uuid::generate()->value), -10));
}

/**
 * Ingresa un pedido por la puerta de integraciones (sin el middleware de la puerta: se prueba el contrato
 * del endpoint, no la credencial de servicio).
 *
 * @param array<string, mixed> $extra
 */
function sodIngest(string $externalId, array $extra = []): TestResponse
{
    test()->withoutMiddleware();

    return test()->postJson('/api/v1/integrations/shipments', $extra + [
        'source' => 'SAP',
        'external_id' => $externalId,
        'customer_name' => 'Cliente Origen',
        'address_text' => 'Av. 6 de Marzo 100, El Alto',
        'lines' => [['sku' => 'SKU-SOD-1', 'product_name' => 'Cocina', 'quantity' => 1]],
    ]);
}

/** Fila del envío ingresado con ese id externo (o null si no se creó). */
function sodRow(string $externalId): ?stdClass
{
    return DB::table('shipments')->where('source', 'SAP')->where('external_id', $externalId)->first();
}

// ───────────────────────── 1. Origen informado por la ingesta ─────────────────────────

it('guarda el origen informado por código o por id, y su departamento', function (): void {
    $cbba = sodNode('distribution_center', 'cochabamba');
    $scz = sodNode('central_warehouse', 'santa_cruz');

    // Por código (sin importar mayúsculas): el pedido va a El Alto pero SALE de Cochabamba.
    $byCode = sodCode('SOD-COD');
    sodIngest($byCode, ['origin_node_code' => strtolower($cbba['code'])])->assertStatus(202);

    $row = sodRow($byCode);
    expect($row?->origin_node_id)->toBe($cbba['id'])
        ->and($row?->origin_department)->toBe('cochabamba')
        ->and($row?->destination_department)->toBe('la_paz');

    // Por id.
    $byId = sodCode('SOD-ID');
    sodIngest($byId, ['origin_node_id' => $scz['id']])->assertStatus(202);

    expect(sodRow($byId)?->origin_node_id)->toBe($scz['id'])
        ->and(sodRow($byId)?->origin_department)->toBe('santa_cruz');

    // Id y código del MISMO nodo: válido.
    $both = sodCode('SOD-BOTH');
    sodIngest($both, ['origin_node_id' => $cbba['id'], 'origin_node_code' => $cbba['code']])->assertStatus(202);

    expect(sodRow($both)?->origin_node_id)->toBe($cbba['id']);
});

it('sin origen informado lo sigue derivando del destino', function (): void {
    sodNode('distribution_center', 'la_paz');
    sodNode('distribution_center', 'cochabamba');

    $order = sodCode('SOD-DER');
    sodIngest($order, ['origin_node_id' => null, 'origin_node_code' => ''])->assertStatus(202);

    $row = sodRow($order);
    expect($row?->origin_node_id)->not->toBeNull()
        ->and($row?->origin_department)->toBe('la_paz')
        ->and($row?->destination_department)->toBe('la_paz');
});

it('rechaza con 422 y el motivo un origen que no existe o que no coincide, sin crear el envío', function (): void {
    $cbba = sodNode('distribution_center', 'cochabamba');
    $scz = sodNode('distribution_center', 'santa_cruz');

    $cases = [
        'origin_node_code' => ['origin_node_code' => 'NO-EXISTE-'.Str::upper(Str::random(6))],
        'origin_node_id' => ['origin_node_id' => Uuid::generate()->value],
        'son nodos distintos' => ['origin_node_id' => $cbba['id'], 'origin_node_code' => $scz['code']],
    ];

    foreach ($cases as $reason => $origin) {
        $order = sodCode('SOD-BAD');
        $response = sodIngest($order, $origin)
            ->assertStatus(422)
            ->assertJsonPath('status', 422)
            ->assertJsonPath('code', 'business_rule_violation');

        expect($response->json('detail'))->toContain($reason)
            ->and($response->json('error.message'))->toBe($response->json('detail'))
            ->and(sodRow($order))->toBeNull();
    }

    // Un id que no tiene forma de UUID lo corta la validación del payload (nunca llega a la base).
    sodIngest(sodCode('SOD-BAD'), ['origin_node_id' => 'no-es-uuid'])->assertStatus(422);
});

it('reenviar el mismo pedido no duplica, no cambia el origen guardado ni vuelve a publicar eventos', function (): void {
    $cbba = sodNode('distribution_center', 'cochabamba');
    $scz = sodNode('distribution_center', 'santa_cruz');

    $order = sodCode('SOD-IDEM');
    sodIngest($order, ['origin_node_id' => $cbba['id']])->assertStatus(202);

    $first = sodRow($order);
    $events = DB::table('outbox_messages')->where('aggregate_id', $first?->id)->count();

    // Mismo pedido, ahora con otro origen (válido): se acepta por idempotencia y no cambia nada.
    sodIngest($order, ['origin_node_id' => $scz['id']])->assertStatus(202);

    expect(DB::table('shipments')->where('source', 'SAP')->where('external_id', $order)->count())->toBe(1)
        ->and(sodRow($order)?->origin_node_id)->toBe($cbba['id'])
        ->and(sodRow($order)?->origin_department)->toBe('cochabamba')
        ->and(DB::table('outbox_messages')->where('aggregate_id', $first?->id)->count())->toBe($events);
});

// ───────────────────── 2. Consulta abierta: datos del cliente por rol ─────────────────────

it('solo admin, supervisor y tienda encuentran un pedido por el nombre o el documento del cliente', function (): void {
    $token = 'Zqx'.Str::lower(Str::random(10));
    $document = '9'.random_int(100000000, 999999999);
    $order = sodCode('SODLKP');
    $shipment = ShipmentRecord::factory()->create([
        'external_id' => $order,
        'order_reference' => $order,
        'customer_name' => 'Zulema '.$token,
        'customer_document_id' => $document.' SC',
    ]);

    $office = [
        [Role::Admin, []],
        [Role::Supervisor, []],
        [Role::Tienda, ['node_id' => Uuid::generate()->value]],
    ];
    foreach ($office as [$role, $links]) {
        sodActingAs($role, $links);

        foreach ([$token, strtoupper($token), $document] as $text) {
            test()->getJson('/api/v1/app/orders/lookup?q='.$text)
                ->assertOk()
                ->assertJsonPath('meta.matched_by', 'search')
                ->assertJsonPath('data.0.id', $shipment->id)
                ->assertJsonPath('data.0.customer_name', 'Zulema '.$token);
        }
    }

    $field = [
        [Role::Chofer, ['driver_id' => Uuid::generate()->value]],
        [Role::Preparador, ['city' => 'la_paz']],
        [Role::Transportista, ['carrier_id' => Uuid::generate()->value]],
    ];
    foreach ($field as [$role, $links]) {
        sodActingAs($role, $links);

        // Por datos del cliente no coincide (no es un 403: simplemente no encuentra nada).
        foreach ([$token, $document] as $text) {
            test()->getJson('/api/v1/app/orders/lookup?q='.$text)
                ->assertStatus(404)
                ->assertJsonPath('code', 'not_found');
        }

        // Por código exacto y por parte del código sigue encontrando CUALQUIER pedido, con la misma ficha.
        test()->getJson('/api/v1/app/orders/lookup?q='.$order)
            ->assertOk()
            ->assertJsonPath('meta.matched_by', 'code')
            ->assertJsonPath('data.0.id', $shipment->id)
            ->assertJsonPath('data.0.customer_name', 'Zulema '.$token);

        test()->getJson('/api/v1/app/orders/lookup?q='.substr($order, 0, -2))
            ->assertOk()
            ->assertJsonPath('meta.matched_by', 'search')
            ->assertJsonPath('data.0.id', $shipment->id);
    }
});

// ─────────────────────────── 3. Persona sin ciudad (modo estricto) ───────────────────────────

it('en modo estricto un preparador sin ciudad no lista nada y sus escrituras responden 403 con el motivo', function (): void {
    $store = sodNode('store', 'santa_cruz');
    $order = sodCode('SOD-CITY');
    $shipment = ShipmentRecord::factory()->forNode($store['id'])->create(['external_id' => $order, 'order_reference' => $order]);

    config(['logistics-shipments.scope.strict_without_city' => true]);

    sodActingAs(Role::Preparador);

    // No lista nada (sin paginar, por offset y por cursor).
    foreach (['', '?page=1&per_page=100', '?cursor=&per_page=100'] as $mode) {
        test()->getJson('/api/v1/app/shipments'.$mode)->assertOk()->assertJsonCount(0, 'data');
    }

    // Escrituras: 403 `out_of_scope_city` con el motivo de la ciudad faltante.
    foreach (['labels', 'vehicle'] as $action) {
        $denied = test()->postJson('/api/v1/app/orders/'.$order.'/'.$action, [])
            ->assertStatus(403)
            ->assertJsonPath('code', 'out_of_scope_city')
            ->assertJsonPath('error.code', 'out_of_scope_city');

        expect($denied->json('detail'))->toContain('ciudad asignada')
            ->and($denied->json('error.message'))->toBe($denied->json('detail'));
    }
    test()->postJson('/api/v1/app/deliveries/scans/load', ['shipment_id' => $shipment->id])
        ->assertStatus(403)
        ->assertJsonPath('code', 'out_of_scope_city');

    // Consultar sigue abierto, en modo solo consulta.
    test()->getJson('/api/v1/app/orders/lookup?q='.$order)
        ->assertOk()
        ->assertJsonPath('data.0.id', $shipment->id)
        ->assertJsonPath('data.0.can_process', false);
});

it('el modo estricto no afecta a admin ni supervisor, ni al preparador que ya tiene ciudad', function (): void {
    $store = sodNode('store', 'santa_cruz');
    $order = sodCode('SOD-CITY');
    $shipment = ShipmentRecord::factory()->forNode($store['id'])->create(['external_id' => $order, 'order_reference' => $order]);

    config(['logistics-shipments.scope.strict_without_city' => true]);

    foreach ([Role::Admin, Role::Supervisor] as $role) {
        sodActingAs($role);

        expect(collect(test()->getJson('/api/v1/app/shipments')->assertOk()->json('data'))->pluck('id')->all())->toContain($shipment->id)
            ->and(test()->postJson('/api/v1/app/orders/'.$order.'/labels', [])->status())->not->toBe(403);
    }

    sodActingAs(Role::Preparador, ['city' => 'santa_cruz']);

    expect(collect(test()->getJson('/api/v1/app/shipments')->assertOk()->json('data'))->pluck('id')->all())->toContain($shipment->id)
        ->and(test()->postJson('/api/v1/app/orders/'.$order.'/labels', [])->status())->not->toBe(403);
});

it('sin el modo estricto un preparador sin ciudad sigue viendo y operando los pedidos', function (): void {
    $store = sodNode('store', 'santa_cruz');
    $order = sodCode('SOD-CITY');
    $shipment = ShipmentRecord::factory()->forNode($store['id'])->create(['external_id' => $order, 'order_reference' => $order]);

    config(['logistics-shipments.scope.strict_without_city' => false]);

    sodActingAs(Role::Preparador);

    expect(collect(test()->getJson('/api/v1/app/shipments')->assertOk()->json('data'))->pluck('id')->all())->toContain($shipment->id)
        ->and(test()->postJson('/api/v1/app/orders/'.$order.'/labels', [])->status())->not->toBe(403);
});
