<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Logistics\Shared\Domain\ValueObject\Uuid;
use Logistics\Shipments\Infrastructure\Persistence\Eloquent\ShipmentRecord;

/**
 * Backfill `shipments:sync-status`: repara los envíos atascados en RECEIVED/PLANNED derivando su estado de
 * ejecución/planificación (solo avanza, idempotente, --dry-run no escribe) y completa el nodo destino de
 * los envíos de retiro con sufijo "#n:{uuid}". Contra PostgreSQL (base logistics_test).
 */
beforeEach(function (): void {
    // Sin preparación propia: la guarda real es el ->skip() encadenado (requiere PostgreSQL).
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

function syncShipment(string $status = 'RECEIVED', ?string $externalId = null): string
{
    $attributes = ['status' => $status];
    if ($externalId !== null) {
        $attributes['external_id'] = $externalId;
        $attributes['source'] = 'commerce';
    }

    return ShipmentRecord::factory()->create($attributes)->id;
}

function syncDelivery(string $shipmentId, string $status): void
{
    DB::table('execution_deliveries')->insert([
        'id' => Uuid::generate()->value,
        'shipment_id' => $shipmentId,
        'vehicle_id' => Uuid::generate()->value,
        'status' => $status,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function syncStatus(string $shipmentId): string
{
    return (string) DB::table('shipments')->where('id', $shipmentId)->value('status');
}

function syncNetworkNode(): string
{
    $id = Uuid::generate()->value;
    DB::table('network_nodes')->insert([
        'id' => $id,
        'code' => 'T-SYNC-'.strtoupper(substr($id, -6)),
        'name' => 'Tienda Sync',
        'type' => 'store',
        'lat' => -17.78,
        'lng' => -63.18,
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $id;
}

it('avanza los envíos atascados según la entrega en ejecución, la ruta y el plan publicado', function (): void {
    $prepared = syncShipment('PLANNED');
    syncDelivery($prepared, 'PREPARED');

    // Flujo vigente: una entrega PENDING ya tiene el vehículo asignado (asignar = preparar) → IN_EXECUTION.
    $assigned = syncShipment('PLANNED');
    syncDelivery($assigned, 'PENDING');

    $delivered = syncShipment();
    syncDelivery($delivered, 'DELIVERED');

    $failed = syncShipment();
    syncDelivery($failed, 'FAILED');

    $onRoute = syncShipment();
    $routeId = Uuid::generate()->value;
    DB::table('execution_driver_routes')->insert([
        'id' => $routeId, 'vehicle_id' => Uuid::generate()->value, 'route_date' => '2026-11-12',
        'status' => 'PLANNED', 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('execution_driver_route_stops')->insert([
        'id' => Uuid::generate()->value, 'route_id' => $routeId, 'shipment_id' => $onRoute, 'sequence' => 1,
        'status' => 'PENDING', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $inPublishedPlan = syncShipment();
    $inProposedPlan = syncShipment();
    foreach (['PUBLISHED' => $inPublishedPlan, 'PROPOSED' => $inProposedPlan] as $planStatus => $shipmentId) {
        $planId = Uuid::generate()->value;
        DB::table('planning_plans')->insert([
            'id' => $planId, 'plan_date' => '2026-11-12', 'vehicle_id' => Uuid::generate()->value,
            'status' => $planStatus, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('planning_plan_stops')->insert([
            'id' => Uuid::generate()->value, 'plan_id' => $planId, 'shipment_id' => $shipmentId,
            'sequence' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    $untouched = syncShipment();

    $this->artisan('shipments:sync-status', ['--only' => 'status'])->assertSuccessful();

    expect(syncStatus($prepared))->toBe('IN_EXECUTION')
        ->and(syncStatus($assigned))->toBe('IN_EXECUTION')
        ->and(syncStatus($delivered))->toBe('DELIVERED')
        ->and(syncStatus($failed))->toBe('IN_EXECUTION')
        ->and(syncStatus($onRoute))->toBe('PLANNED')
        ->and(syncStatus($inPublishedPlan))->toBe('PLANNED')
        // Un plan solo PROPUESTO todavía se re-arma: no marca PLANNED.
        ->and(syncStatus($inProposedPlan))->toBe('RECEIVED')
        ->and(syncStatus($untouched))->toBe('RECEIVED');
});

it('no escribe en --dry-run y es idempotente en una segunda corrida', function (): void {
    $id = syncShipment();
    syncDelivery($id, 'LOADED');

    $this->artisan('shipments:sync-status', ['--only' => 'status', '--dry-run' => true])
        ->expectsOutputToContain('Cambiarían')
        ->assertSuccessful();
    expect(syncStatus($id))->toBe('RECEIVED');

    $this->artisan('shipments:sync-status', ['--only' => 'status'])->assertSuccessful();
    expect(syncStatus($id))->toBe('IN_EXECUTION');

    $this->artisan('shipments:sync-status', ['--only' => 'status'])
        ->expectsOutputToContain('a IN_EXECUTION: 0')
        ->assertSuccessful();
    expect(syncStatus($id))->toBe('IN_EXECUTION');
});

it('nunca retrocede ni toca envíos cerrados', function (): void {
    $cancelled = syncShipment('CANCELLED');
    syncDelivery($cancelled, 'DELIVERED');

    $this->artisan('shipments:sync-status', ['--only' => 'status'])->assertSuccessful();

    expect(syncStatus($cancelled))->toBe('CANCELLED');
});

it('completa el nodo destino de los envíos de retiro con sufijo "#n:{uuid}" de un nodo existente', function (): void {
    $node = syncNetworkNode();
    $pickup = syncShipment('RECEIVED', 'ORD-SYNC-1#n:'.$node);
    $orphan = syncShipment('RECEIVED', 'ORD-SYNC-2#n:'.Uuid::generate()->value);
    $home = syncShipment('RECEIVED', 'ORD-SYNC-3');

    $this->artisan('shipments:sync-status', ['--only' => 'nodes'])->assertSuccessful();

    expect(DB::table('shipments')->where('id', $pickup)->value('destination_node_id'))->toBe($node)
        ->and(DB::table('shipments')->where('id', $orphan)->value('destination_node_id'))->toBeNull()
        ->and(DB::table('shipments')->where('id', $home)->value('destination_node_id'))->toBeNull();
});

it('rechaza una fase desconocida', function (): void {
    $this->artisan('shipments:sync-status', ['--only' => 'otra'])->assertExitCode(2);
});
