<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Logistics\Execution\Application\Command\AssembleDailyRoute\AssembleDailyRoute;
use Logistics\Execution\Application\Command\AssembleDailyRoute\RouteStopInput;
use Logistics\Execution\Application\Command\AttachShipmentToRoute\AttachShipmentToRoute;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Domain\ValueObject\Uuid;

/**
 * Regla de la ruta del día: la ruta de un camión para un día contiene SOLO las paradas del plan publicado de
 * ese camión y día más las que se le sumaron ESE día; nunca un envío ya cerrado en una fecha anterior ni un
 * pedido arrastrado de otra jornada. Se cubren la lectura defensiva (GET /app/vehicles/{id}/route) y el
 * comando de saneo (logistics:execution:reconcile-route-stops) sobre datos viejos sembrados a mano.
 */
beforeEach(function (): void {
    $this->withoutMiddleware();
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

const RSR_ROUTE_DAY = '2026-10-20';

const RSR_PRIOR_DAY = '2026-10-18';

const RSR_PENDING = 'PENDING';

const RSR_COMMAND = 'logistics:execution:reconcile-route-stops';

const RSR_STOPS_TABLE = 'execution_driver_route_stops';

/** Instante de mediodía (UTC) de un día calendario, para no cruzar el borde del día en la zona local. */
function rsrNoon(string $day): string
{
    return $day.'T12:00:00+00:00';
}

/** Siembra una ruta del camión para un día; cerrada si se indica. Devuelve el id de la ruta. */
function rsrRoute(string $vehicle, string $day, string $status = 'IN_PROGRESS', bool $closed = false): string
{
    $id = Uuid::generate()->value;
    DB::table('execution_driver_routes')->insert([
        'id' => $id,
        'vehicle_id' => $vehicle,
        'route_date' => $day,
        'status' => $status,
        'closed_at' => $closed ? rsrNoon($day) : null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $id;
}

/**
 * Siembra una parada de la ruta. `attached_at` null = del plan publicado; con valor = sumada ese día.
 *
 * @param array<string, mixed> $overrides
 */
function rsrStop(string $routeId, string $shipment, int $sequence, array $overrides = []): void
{
    DB::table(RSR_STOPS_TABLE)->insert(array_merge([
        'id' => Uuid::generate()->value,
        'route_id' => $routeId,
        'shipment_id' => $shipment,
        'sequence' => $sequence,
        'status' => RSR_PENDING,
        'attached_at' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides));
}

/** Siembra un plan PUBLICADO del camión/día con esos envíos como paradas. */
function rsrPublishPlan(string $vehicle, string $day, string ...$shipmentIds): void
{
    $planId = Uuid::generate()->value;
    DB::table('planning_plans')->insert([
        'id' => $planId,
        'plan_date' => $day,
        'vehicle_id' => $vehicle,
        'status' => 'PUBLISHED',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $sequence = 1;
    foreach ($shipmentIds as $shipmentId) {
        DB::table('planning_plan_stops')->insert([
            'id' => Uuid::generate()->value,
            'plan_id' => $planId,
            'shipment_id' => $shipmentId,
            'sequence' => $sequence++,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}

/** Envíos de las paradas de una ruta, en orden de secuencia. */
function rsrShipmentsOf(string $routeId): array
{
    return DB::table(RSR_STOPS_TABLE)
        ->where('route_id', $routeId)
        ->orderBy('sequence')
        ->pluck('shipment_id')
        ->all();
}

it('no devuelve la parada de un envío entregado en una fecha anterior y deja los contadores coherentes', function (): void {
    $vehicle = Uuid::generate()->value;
    $valid = Uuid::generate()->value;
    $deliveredBefore = Uuid::generate()->value;

    $routeId = rsrRoute($vehicle, RSR_ROUTE_DAY);
    rsrStop($routeId, $valid, 1);
    // Entregada días atrás (COMPLETED con settled_at anterior): no es una parada de la ruta de hoy.
    rsrStop($routeId, $deliveredBefore, 2, ['status' => 'COMPLETED', 'settled_at' => rsrNoon(RSR_PRIOR_DAY)]);

    $this->getJson("/api/v1/app/vehicles/{$vehicle}/route?date=".RSR_ROUTE_DAY)
        ->assertOk()
        ->assertJsonCount(1, 'data.stops')
        ->assertJsonPath('data.stops.0.shipment_id', $valid)
        ->assertJsonPath('data.total_stops', 1)
        ->assertJsonPath('data.completed_stops', 0)
        ->assertJsonPath('data.stops_remaining', 1);
});

it('la asignación explícita del día sí aparece en la ruta de hoy', function (): void {
    $vehicle = Uuid::generate()->value;
    $today = now()->format('Y-m-d');
    $planned = Uuid::generate()->value;
    $assigned = Uuid::generate()->value;

    /** @var CommandBus $bus */
    $bus = app(CommandBus::class);
    $bus->dispatch(new AssembleDailyRoute(
        vehicleId: $vehicle,
        routeDate: $today,
        stops: [new RouteStopInput(shipmentId: $planned, sequence: 1)],
    ));
    $bus->dispatch(new AttachShipmentToRoute(vehicleId: $vehicle, shipmentId: $assigned, orderReference: 'ORD-RSR'));

    $this->getJson("/api/v1/app/vehicles/{$vehicle}/route?date={$today}")
        ->assertOk()
        ->assertJsonPath('data.total_stops', 2);
});

it('en simulación el comando no toca ninguna fila y reporta lo que quitaría', function (): void {
    $vehicle = Uuid::generate()->value;
    $first = Uuid::generate()->value;
    $second = Uuid::generate()->value;
    $dragged = Uuid::generate()->value;
    $deliveredBefore = Uuid::generate()->value;

    $routeId = rsrRoute($vehicle, RSR_ROUTE_DAY);
    rsrStop($routeId, $first, 1);
    rsrStop($routeId, $second, 2);
    rsrStop($routeId, $dragged, 3);
    rsrStop($routeId, $deliveredBefore, 4, ['status' => 'COMPLETED', 'settled_at' => rsrNoon(RSR_PRIOR_DAY)]);
    rsrPublishPlan($vehicle, RSR_ROUTE_DAY, $first, $second);

    $this->artisan(RSR_COMMAND)->assertSuccessful();

    expect(DB::table(RSR_STOPS_TABLE)->where('route_id', $routeId)->count())->toBe(4);
});

it('con --force quita las paradas de mas, recompacta la secuencia y es idempotente', function (): void {
    $vehicle = Uuid::generate()->value;
    $first = Uuid::generate()->value;
    $second = Uuid::generate()->value;
    $dragged = Uuid::generate()->value;
    $deliveredBefore = Uuid::generate()->value;

    $routeId = rsrRoute($vehicle, RSR_ROUTE_DAY);
    rsrStop($routeId, $first, 1);
    rsrStop($routeId, $dragged, 2);
    rsrStop($routeId, $second, 3);
    rsrStop($routeId, $deliveredBefore, 4, ['status' => 'COMPLETED', 'settled_at' => rsrNoon(RSR_PRIOR_DAY)]);
    rsrPublishPlan($vehicle, RSR_ROUTE_DAY, $first, $second);

    $this->artisan(RSR_COMMAND, ['--force' => true])->assertSuccessful();

    expect(rsrShipmentsOf($routeId))->toBe([$first, $second]);
    expect(DB::table(RSR_STOPS_TABLE)->where('route_id', $routeId)->pluck('sequence')->all())->toBe([1, 2]);

    // Idempotente: una segunda corrida ya no quita nada.
    $this->artisan(RSR_COMMAND, ['--force' => true])->assertSuccessful();
    expect(DB::table(RSR_STOPS_TABLE)->where('route_id', $routeId)->count())->toBe(2);
});

it('con --force conserva una parada con actividad ese dia aunque no este en el plan', function (): void {
    $vehicle = Uuid::generate()->value;
    $planned = Uuid::generate()->value;
    $arrivedToday = Uuid::generate()->value;

    $routeId = rsrRoute($vehicle, RSR_ROUTE_DAY);
    rsrStop($routeId, $planned, 1);
    // No está en el plan, pero el camión llegó a esa parada ESE día: hubo trabajo real, no se quita.
    rsrStop($routeId, $arrivedToday, 2, ['arrived_at' => rsrNoon(RSR_ROUTE_DAY), 'arrival_source' => 'manual']);
    rsrPublishPlan($vehicle, RSR_ROUTE_DAY, $planned);

    $this->artisan(RSR_COMMAND, ['--force' => true])->assertSuccessful();

    expect(rsrShipmentsOf($routeId))->toBe([$planned, $arrivedToday]);
});

it('con --force conserva una parada con un escaneo de terreno ese dia', function (): void {
    $vehicle = Uuid::generate()->value;
    $planned = Uuid::generate()->value;
    $scannedToday = Uuid::generate()->value;

    $routeId = rsrRoute($vehicle, RSR_ROUTE_DAY);
    rsrStop($routeId, $planned, 1);
    rsrStop($routeId, $scannedToday, 2);
    rsrPublishPlan($vehicle, RSR_ROUTE_DAY, $planned);

    // Entrega abierta (no entregada) + un escaneo de carga ese mismo día para el envío arrastrado.
    $deliveryId = Uuid::generate()->value;
    DB::table('execution_deliveries')->insert([
        'id' => $deliveryId,
        'shipment_id' => $scannedToday,
        'vehicle_id' => $vehicle,
        'status' => 'LOADED',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('execution_scan_events')->insert([
        'id' => Uuid::generate()->value,
        'delivery_id' => $deliveryId,
        'type' => 'load',
        'unit_id' => Uuid::generate()->value,
        'lat' => -16.5,
        'lng' => -68.15,
        'device_id' => 'dev',
        'user_id' => 'usr',
        'occurred_at' => rsrNoon(RSR_ROUTE_DAY),
        'idempotency_key' => 'rsr-'.Uuid::generate()->value,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->artisan(RSR_COMMAND, ['--force' => true])->assertSuccessful();

    expect(rsrShipmentsOf($routeId))->toBe([$planned, $scannedToday]);
});

it('el comando nunca toca una ruta ya cerrada', function (): void {
    $vehicle = Uuid::generate()->value;
    $dragged = Uuid::generate()->value;

    $routeId = rsrRoute($vehicle, RSR_ROUTE_DAY, status: 'COMPLETED', closed: true);
    rsrStop($routeId, $dragged, 1);
    // Sin plan publicado: si la ruta no estuviera cerrada, la parada se iría; cerrada, se respeta entera.

    $this->artisan(RSR_COMMAND, ['--force' => true])->assertSuccessful();

    expect(rsrShipmentsOf($routeId))->toBe([$dragged]);
});
