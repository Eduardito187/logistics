<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Logistics\Outbox\Domain\OutboxMessage;
use Logistics\Outbox\Domain\ValueObject\OutboxMessageId;
use Logistics\Outbox\Domain\ValueObject\OutboxStatus;
use Logistics\Outbox\Infrastructure\Dispatch\FanOutMessageDispatcher;
use Logistics\Shared\Domain\ValueObject\Uuid;
use Logistics\Shipments\Infrastructure\Dispatch\ShipmentStatusProgressionDispatcher;
use Logistics\Shipments\Infrastructure\Persistence\Eloquent\ShipmentRecord;

/**
 * Reactor planificación/ejecución → envíos: `shipments.status` avanza de forma monótona e idempotente con
 * los hechos del outbox, nunca retrocede, ignora envíos desconocidos sin lanzar (no hay dead-letter) y
 * queda registrado ÚLTIMO en el abanico de cada hecho. Contra PostgreSQL (base logistics_test).
 */
beforeEach(function (): void {
    // Sin base PostgreSQL no hay nada que probar.
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

/**
 * @param array<string, mixed> $payload
 */
function sspMessage(string $event, array $payload, ?string $aggregateId = null): OutboxMessage
{
    return OutboxMessage::restore(
        OutboxMessageId::generate(),
        Uuid::generate(),
        'test.aggregate',
        $aggregateId ?? Uuid::generate()->value,
        $event,
        $payload,
        OutboxStatus::Pending,
        0,
        new DateTimeImmutable,
        new DateTimeImmutable,
        null,
        null,
    );
}

function sspReactor(): ShipmentStatusProgressionDispatcher
{
    return app(ShipmentStatusProgressionDispatcher::class);
}

function sspShipment(string $status = 'RECEIVED'): string
{
    return ShipmentRecord::factory()->create(['status' => $status])->id;
}

function sspStatusOf(string $shipmentId): string
{
    return (string) DB::table('shipments')->where('id', $shipmentId)->value('status');
}

it('avanza RECEIVED → PLANNED → IN_EXECUTION → DELIVERED con los hechos de ejecución', function (): void {
    $id = sspShipment();
    $reactor = sspReactor();

    $reactor->dispatch(sspMessage('execution.route_stop_attached', ['shipment_id' => $id]));
    expect(sspStatusOf($id))->toBe('PLANNED');

    $reactor->dispatch(sspMessage('execution.shipment_prepared', ['shipment_id' => $id]));
    expect(sspStatusOf($id))->toBe('IN_EXECUTION');

    $reactor->dispatch(sspMessage('execution.shipment_loaded', ['shipment_id' => $id]));
    $reactor->dispatch(sspMessage('execution.shipment_departed', ['shipment_id' => $id]));
    expect(sspStatusOf($id))->toBe('IN_EXECUTION');

    $reactor->dispatch(sspMessage('execution.shipment_delivered', ['shipment_id' => $id]));
    expect(sspStatusOf($id))->toBe('DELIVERED');
});

it('nunca retrocede: hechos atrasados sobre un envío entregado o anulado no cambian nada', function (string $closed): void {
    $id = sspShipment($closed);
    $reactor = sspReactor();

    foreach (['execution.route_stop_attached', 'execution.shipment_loaded', 'execution.delivery_failed', 'execution.shipment_delivered'] as $event) {
        $reactor->dispatch(sspMessage($event, ['shipment_id' => $id]));
    }

    expect(sspStatusOf($id))->toBe($closed);
})->with(['DELIVERED', 'CANCELLED']);

it('una asignación a camión tardía no baja a PLANNED un envío ya en ejecución', function (): void {
    $id = sspShipment('IN_EXECUTION');

    sspReactor()->dispatch(sspMessage('execution.route_stop_attached', ['shipment_id' => $id]));

    expect(sspStatusOf($id))->toBe('IN_EXECUTION');
});

it('una entrega fallida deja el envío al menos en IN_EXECUTION', function (): void {
    $id = sspShipment('PLANNED');

    sspReactor()->dispatch(sspMessage('execution.delivery_failed', ['shipment_id' => $id, 'reason' => 'absent']));

    expect(sspStatusOf($id))->toBe('IN_EXECUTION');
});

it('ignora envíos desconocidos o ids inválidos sin lanzar (el outbox no manda a dead-letter)', function (): void {
    $reactor = sspReactor();

    expect(fn () => $reactor->dispatch(sspMessage('execution.shipment_loaded', ['shipment_id' => Uuid::generate()->value])))
        ->not->toThrow(Throwable::class)
        ->and(fn () => $reactor->dispatch(sspMessage('execution.shipment_loaded', ['shipment_id' => 'no-es-uuid'])))
        ->not->toThrow(Throwable::class)
        ->and(fn () => $reactor->dispatch(sspMessage('execution.shipment_loaded', [])))
        ->not->toThrow(Throwable::class)
        ->and(fn () => $reactor->dispatch(sspMessage('planning.plan_published', ['plan_id' => Uuid::generate()->value])))
        ->not->toThrow(Throwable::class);
});

it('la reentrega del mismo hecho es idempotente: un solo cambio y un solo hecho al outbox', function (): void {
    $id = sspShipment();
    $reactor = sspReactor();
    $message = sspMessage('execution.shipment_loaded', ['shipment_id' => $id]);

    $reactor->dispatch($message);
    $reactor->dispatch($message);
    $reactor->dispatch(sspMessage('execution.shipment_prepared', ['shipment_id' => $id]));

    expect(sspStatusOf($id))->toBe('IN_EXECUTION')
        ->and(DB::table('outbox_messages')
            ->where('aggregate_id', $id)
            ->where('event_name', 'shipments.shipment_execution_started')
            ->count())->toBe(1);
});

it('al publicarse un plan, sus paradas pasan a PLANNED (y solo ellas)', function (): void {
    $planned = [sspShipment(), sspShipment()];
    $outside = sspShipment();

    $planId = Uuid::generate()->value;
    DB::table('planning_plans')->insert([
        'id' => $planId,
        'plan_date' => '2026-11-12',
        'vehicle_id' => Uuid::generate()->value,
        'status' => 'PUBLISHED',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    foreach ($planned as $sequence => $shipmentId) {
        DB::table('planning_plan_stops')->insert([
            'id' => Uuid::generate()->value,
            'plan_id' => $planId,
            'shipment_id' => $shipmentId,
            'sequence' => $sequence + 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    sspReactor()->dispatch(sspMessage('planning.plan_published', ['plan_id' => $planId], $planId));

    expect(sspStatusOf($planned[0]))->toBe('PLANNED')
        ->and(sspStatusOf($planned[1]))->toBe('PLANNED')
        ->and(sspStatusOf($outside))->toBe('RECEIVED');
});

it('al armarse la ruta del camión, sus paradas pasan a PLANNED', function (): void {
    $id = sspShipment();
    $routeId = Uuid::generate()->value;
    DB::table('execution_driver_routes')->insert([
        'id' => $routeId,
        'vehicle_id' => Uuid::generate()->value,
        'route_date' => '2026-11-12',
        'status' => 'PLANNED',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('execution_driver_route_stops')->insert([
        'id' => Uuid::generate()->value,
        'route_id' => $routeId,
        'shipment_id' => $id,
        'sequence' => 1,
        'status' => 'PENDING',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    sspReactor()->dispatch(sspMessage('execution.driver_route_assembled', ['route_id' => $routeId], $routeId));

    expect(sspStatusOf($id))->toBe('PLANNED');
});

it('queda registrado en el ruteo del outbox, ÚLTIMO en cada abanico', function (): void {
    /** @var array<string, string> $routes */
    $routes = config('logistics-outbox.routes');
    /** @var array<string, list<string>> $fanout */
    $fanout = config('logistics-outbox.fanout');

    foreach (ShipmentStatusProgressionDispatcher::handledEvents() as $event) {
        $route = $routes[$event] ?? null;

        if ($route === FanOutMessageDispatcher::class) {
            $targets = $fanout[$event] ?? [];
            expect($targets)->not->toBeEmpty()
                ->and(end($targets))->toBe(ShipmentStatusProgressionDispatcher::class);
        } else {
            expect($route)->toBe(ShipmentStatusProgressionDispatcher::class);
        }
    }
});
