<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Http\Middleware\EnsureDeviceToken;
use App\Http\Middleware\IdempotentRequests;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Logistics\Execution\Application\Command\OpenNodeReception\OpenNodeReception;
use Logistics\Execution\Application\Command\ReceiveUnitAtNode\ReceiveUnitAtNode;
use Logistics\Execution\Application\Command\ReconcileNodeReception\ReconcileNodeReception;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Domain\ValueObject\Uuid;
use Logistics\Shipments\Infrastructure\Persistence\Eloquent\ShipmentRecord;

/**
 * #21 — GET /app/node-receptions?node_id=&status=open: lista paginada (meta estándar) con el envío legible
 * (`order_reference`, `customer_name`) y `reception: {status, expected_count, received_count, missing_count,
 * surplus_count, opened_at}`, en un número fijo de consultas (sin N+1). Alcance por tienda.
 */
beforeEach(function (): void {
    $this->withoutMiddleware([EnsureDeviceToken::class, IdempotentRequests::class]);
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

function listActingAs(Role $role, ?string $nodeId): void
{
    $user = User::factory()->create();
    $user->assignRole($role);
    $user->forceFill(['node_id' => $nodeId])->save();
    test()->actingAs($user, 'sanctum');
}

/**
 * Abre una recepción en el nodo para un envío real (con referencia de pedido y cliente) y recibe los
 * productos indicados.
 *
 * @param list<string> $received
 */
function listSeedReception(string $node, string $orderReference, array $received = []): string
{
    $shipment = ShipmentRecord::factory()->forNode($node)->create([
        'order_reference' => $orderReference,
        'customer_name' => 'Cliente '.$orderReference,
    ]);

    /** @var CommandBus $bus */
    $bus = app(CommandBus::class);
    $bus->dispatch(new OpenNodeReception(
        shipmentId: $shipment->id,
        originNodeId: Uuid::generate()->value,
        destinationNodeId: $node,
        originNodeKind: 'CD',
        destinationNodeKind: 'STORE',
        expectedUnitIds: ['U-1', 'U-2', 'U-3'],
        occurredAt: '2026-11-12T08:00:00+00:00',
    ));
    foreach ($received as $unit) {
        $bus->dispatch(new ReceiveUnitAtNode(
            shipmentId: $shipment->id,
            destinationNodeId: $node,
            unitId: $unit,
            lat: null,
            lng: null,
            deviceId: 'device-01',
            userId: 'user-07',
            occurredAt: '2026-11-12T09:00:00+00:00',
            idempotencyKey: 'list-'.$shipment->id.'-'.$unit,
        ));
    }

    return $shipment->id;
}

it('lists open receptions of a node with counts and the readable shipment reference', function (): void {
    $node = Uuid::generate()->value;
    $shipment = listSeedReception($node, 'ORD-1001', ['U-1', 'U-9']);
    listSeedReception(Uuid::generate()->value, 'ORD-OTHER'); // otro nodo: no aparece
    listActingAs(Role::Supervisor, null);

    $response = $this->getJson("/api/v1/app/node-receptions?node_id={$node}&status=open")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('meta.page', 1);

    expect($response->json('data.0.shipment_id'))->toBe($shipment)
        ->and($response->json('data.0.order_reference'))->toBe('ORD-1001')
        ->and($response->json('data.0.customer_name'))->toBe('Cliente ORD-1001')
        ->and($response->json('data.0.reception'))->toMatchArray([
            'status' => 'OPEN',
            'expected_count' => 3,
            'received_count' => 2,
            'missing_count' => 2,
            'surplus_count' => 1,
        ])
        ->and($response->json('data.0.reception.opened_at'))->not->toBeNull();
});

it('filters by status', function (): void {
    $node = Uuid::generate()->value;
    $open = listSeedReception($node, 'ORD-OPEN');
    $closed = listSeedReception($node, 'ORD-CLOSED');
    app(CommandBus::class)->dispatch(new ReconcileNodeReception($closed, $node, 'device-01', 'user-07', '2026-11-12T10:00:00+00:00'));
    listActingAs(Role::Supervisor, null);

    $this->getJson("/api/v1/app/node-receptions?node_id={$node}&status=open")
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.shipment_id', $open);
    $this->getJson("/api/v1/app/node-receptions?node_id={$node}&status=reconciled")
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.shipment_id', $closed);
});

it('paginates with the standard meta', function (): void {
    $node = Uuid::generate()->value;
    foreach (range(1, 3) as $i) {
        listSeedReception($node, 'ORD-P'.$i);
    }
    listActingAs(Role::Supervisor, null);

    $this->getJson("/api/v1/app/node-receptions?node_id={$node}&per_page=2&page=2")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('meta.total', 3)
        ->assertJsonPath('meta.total_pages', 2)
        ->assertJsonPath('meta.prev_page', 1);
});

it('runs a fixed number of queries regardless of the page size (no N+1)', function (): void {
    $node = Uuid::generate()->value;
    foreach (range(1, 5) as $i) {
        listSeedReception($node, 'ORD-Q'.$i, ['U-1']);
    }
    listActingAs(Role::Supervisor, null);

    DB::flushQueryLog();
    DB::enableQueryLog();
    $this->getJson("/api/v1/app/node-receptions?node_id={$node}")->assertOk()->assertJsonCount(5, 'data');
    $receptionQueries = collect(DB::getQueryLog())
        ->filter(static fn (array $q): bool => str_contains((string) $q['query'], 'execution_node_reception'))
        ->count();
    DB::disableQueryLog();

    // count + página + productos recibidos de toda la página.
    expect($receptionQueries)->toBeLessThanOrEqual(3);
});

it('scopes a store user to its own node and rejects another node', function (): void {
    $myNode = Uuid::generate()->value;
    $mine = listSeedReception($myNode, 'ORD-MINE');
    $otherNode = Uuid::generate()->value;
    listSeedReception($otherNode, 'ORD-NOT-MINE');
    listActingAs(Role::Tienda, $myNode);

    // Sin node_id: la tienda ve solo lo suyo.
    $this->getJson('/api/v1/app/node-receptions?status=open')
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.shipment_id', $mine);

    $this->getJson("/api/v1/app/node-receptions?node_id={$otherNode}")
        ->assertStatus(403)->assertJsonPath('code', 'forbidden');
});

it('rejects an unknown status with 422', function (): void {
    listActingAs(Role::Supervisor, null);

    $this->getJson('/api/v1/app/node-receptions?status=lost')->assertStatus(422);
});
