<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Outbox\Domain\OutboxMessage;
use Logistics\Outbox\Domain\ValueObject\OutboxMessageId;
use Logistics\Outbox\Domain\ValueObject\OutboxStatus;
use Logistics\Planning\Application\Command\ReoptimizeRoute\ReoptimizeRoute;
use Logistics\Planning\Application\Command\ReoptimizeRoute\ReoptimizeStopInput;
use Logistics\Planning\Application\Service\DailyPlanRunner;
use Logistics\Planning\Database\Factories\PlanRecordFactory;
use Logistics\Planning\Domain\Contract\AutoPlanRunRepository;
use Logistics\Planning\Domain\Contract\FleetAvailabilityProvider;
use Logistics\Planning\Domain\Contract\PendingDeliveryProvider;
use Logistics\Planning\Domain\Contract\PlanningOriginLocator;
use Logistics\Planning\Domain\Contract\PlanRepository;
use Logistics\Planning\Domain\Contract\RoutingBackend;
use Logistics\Planning\Domain\Model\AutoPlanRun;
use Logistics\Planning\Domain\ValueObject\AutoPlanParameters;
use Logistics\Planning\Domain\ValueObject\AutoPlanRunId;
use Logistics\Planning\Domain\ValueObject\AutoPlanRunStatus;
use Logistics\Planning\Domain\ValueObject\DeliveryCandidate;
use Logistics\Planning\Domain\ValueObject\Eta;
use Logistics\Planning\Domain\ValueObject\GeoPoint;
use Logistics\Planning\Domain\ValueObject\PlanDate;
use Logistics\Planning\Domain\ValueObject\PlanId;
use Logistics\Planning\Domain\ValueObject\PlannedRoute;
use Logistics\Planning\Domain\ValueObject\PlanStrategy;
use Logistics\Planning\Domain\ValueObject\RoutingResult;
use Logistics\Planning\Infrastructure\Adapter\EloquentPendingDeliveryProvider;
use Logistics\Planning\Infrastructure\Adapter\EloquentRouteComparisonProblemProvider;
use Logistics\Planning\Infrastructure\Outbox\VehicleIncidentReplanningDispatcher;
use Logistics\Planning\Infrastructure\Persistence\Eloquent\PlannedStopRecord;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Domain\Clock\Clock;
use Logistics\Shared\Domain\ValueObject\Uuid;

// Ejercita las puertas HTTP del contexto (PlanController / AutoPlanController + sus FormRequest), el
// runner del auto-planificador, el handler de re-optimización, el dispatcher de incidentes del outbox y
// los adaptadores de Infrastructure. Se omite el middleware de puerta para probar solo el contrato de los
// controllers y la cadena CQRS; el motor OSRM se fuerza al fallback con Http::fake (copiado de los tests
// que ya pasan en el contexto).

beforeEach(function (): void {
    // Sin OSRM alcanzable el ruteo degrada a cercanía/haversine: el test corre offline y determinista.
    Http::fake(['*' => Http::response(null, 500)]);
    $this->withoutMiddleware();
});

// ──────────────────────────── PlanController (puerta app /api/v1/app/plans) ────────────────────────────

it('GET /plans lista los planes del día', function (): void {
    $date = (new DateTimeImmutable('today'))->format('Y-m-d');
    PlanRecordFactory::new()->onDate($date)->proposed()->withStops(2)->create();

    $this->getJson("/api/v1/app/plans?date={$date}")
        ->assertOk()
        ->assertJsonPath('meta.count', 1);
});

it('GET /plans/{id} devuelve el plan y 404 cuando no existe', function (): void {
    $plan = PlanRecordFactory::new()->proposed()->withStops(3)->create();

    $this->getJson("/api/v1/app/plans/{$plan->getKey()}")
        ->assertOk()
        ->assertJsonPath('data.id', (string) $plan->getKey());

    $this->getJson('/api/v1/app/plans/'.Str::uuid7())->assertNotFound();
});

it('GET /plans/{id}/eta devuelve la ETA por parada', function (): void {
    $plan = PlanRecordFactory::new()->proposed()->withStops(2)->create();

    $this->getJson("/api/v1/app/plans/{$plan->getKey()}/eta")
        ->assertOk()
        ->assertJsonPath('data.plan_id', (string) $plan->getKey());

    $this->getJson('/api/v1/app/plans/'.Str::uuid7().'/eta')->assertNotFound();
});

it('POST /plans genera la propuesta automática de un vehículo', function (): void {
    $vehicleId = (string) Str::uuid7();

    $this->postJson('/api/v1/app/plans', [
        'plan_date' => (new DateTimeImmutable('today'))->format('Y-m-d'),
        'vehicle_id' => $vehicleId,
        'depart_at' => (new DateTimeImmutable('today 07:30:00'))->format(DateTimeInterface::ATOM),
        'stops' => [
            ['shipment_id' => (string) Str::uuid7(), 'latitude' => -16.4955, 'longitude' => -68.1336],
            ['shipment_id' => (string) Str::uuid7(), 'latitude' => -16.5089, 'longitude' => -68.1193],
        ],
    ])->assertStatus(201)
        ->assertJsonPath('data.vehicle_id', $vehicleId);
});

it('POST /plans valida la entrada (422 sin paradas)', function (): void {
    $this->postJson('/api/v1/app/plans', [
        'plan_date' => 'no-es-fecha',
        'vehicle_id' => 'no-uuid',
        'depart_at' => 'x',
        'stops' => [],
    ])->assertStatus(422);
});

it('POST /plans/{id}/stops agrega un envío y PUT /sequence reordena', function (): void {
    $plan = PlanRecordFactory::new()->withStops(2)->create();
    $newShipment = (string) Str::uuid7();

    $this->postJson("/api/v1/app/plans/{$plan->getKey()}/stops", [
        'shipment_id' => $newShipment,
    ])->assertOk();

    /** @var list<string> $ids */
    $ids = PlannedStopRecord::query()
        ->where('plan_id', $plan->getKey())
        ->orderBy('sequence')
        ->pluck('shipment_id')
        ->all();

    $this->putJson("/api/v1/app/plans/{$plan->getKey()}/sequence", [
        'ordered_shipment_ids' => array_reverse($ids),
    ])->assertOk();
});

it('POST /plans/{id}/publish publica un plan propuesto', function (): void {
    $plan = PlanRecordFactory::new()->proposed()->withStops(2)->create();

    $this->postJson("/api/v1/app/plans/{$plan->getKey()}/publish")
        ->assertOk()
        ->assertJsonPath('data.status', 'PUBLISHED');
});

// ──────────────────────────── AutoPlanController (/api/v1/app/plans/auto) ────────────────────────────

it('POST /plans/auto encola la corrida del día y la devuelve (202)', function (): void {
    // El cálculo pesado va a la cola: se falsea para no correr el worker dentro del request.
    Queue::fake();

    $this->postJson('/api/v1/app/plans/auto', [
        'plan_date' => (new DateTimeImmutable('today'))->format('Y-m-d'),
        'strategy' => 'optimization',
    ])->assertStatus(202);
});

it('GET /plans/auto devuelve la corrida y 404 cuando no hubo', function (): void {
    $date = (new DateTimeImmutable('today'))->format('Y-m-d');
    DB::table('planning_auto_plan_runs')->insert([
        'id' => (string) Str::uuid7(),
        'plan_date' => $date,
        'strategy' => 'optimization',
        'status' => 'completed',
        'requested_at' => now(),
        'completed_at' => now(),
        'trucks_planned' => 2,
        'stops_planned' => 6,
        'unassigned_count' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->getJson("/api/v1/app/plans/auto?date={$date}&strategy=optimization")
        ->assertOk()
        ->assertJsonPath('data.status', 'completed');

    $this->getJson('/api/v1/app/plans/auto?date=2099-01-01&strategy=slots')->assertNotFound();
});

// ──────────────────────────── ReoptimizeRoute (handler por el CommandBus) ────────────────────────────

it('ReoptimizeRoute re-secuencia la ruta de un plan por el bus', function (): void {
    $plan = PlanRecordFactory::new()->proposed()->withStops(3)->create();

    /** @var list<string> $ids */
    $ids = PlannedStopRecord::query()
        ->where('plan_id', $plan->getKey())
        ->orderBy('sequence')
        ->pluck('shipment_id')
        ->all();

    $stops = [];
    $lat = -16.50;
    foreach ($ids as $shipmentId) {
        $stops[] = new ReoptimizeStopInput($shipmentId, $lat, -68.15);
        $lat -= 0.01;
    }

    app(CommandBus::class)->dispatch(new ReoptimizeRoute(
        planId: (string) $plan->getKey(),
        depotLatitude: -16.5,
        depotLongitude: -68.15,
        stops: $stops,
        departAt: (new DateTimeImmutable('today 07:30:00'))->format(DateTimeInterface::ATOM),
        reason: 'incident',
    ));

    expect(PlannedStopRecord::query()->where('plan_id', $plan->getKey())->count())->toBe(3);
});

// ──────────────────────────── DailyPlanRunner (cálculo pesado del worker) ────────────────────────────

it('DailyPlanRunner procesa una corrida encolada y la cierra con su resumen', function (): void {
    $runs = app(AutoPlanRunRepository::class);
    $plans = app(PlanRepository::class);

    // Puertos de lectura y backend falseados: una ruta con una parada para materializar un plan real.
    $pending = new class implements PendingDeliveryProvider
    {
        public function pendingFor(PlanDate $date): array
        {
            return [];
        }
    };
    $fleet = new class implements FleetAvailabilityProvider
    {
        public function availableFor(PlanDate $date): array
        {
            return [];
        }
    };
    $origin = new class implements PlanningOriginLocator
    {
        public function locate(?string $originNodeId): ?GeoPoint
        {
            return null;
        }
    };
    $backend = new class implements RoutingBackend
    {
        public function route(
            DateTimeImmutable $departAt,
            ?GeoPoint $origin,
            array $candidates,
            array $vehicles,
            AutoPlanParameters $params,
        ): RoutingResult {
            // Ids en formato UUID: el plan y sus paradas se persisten en columnas uuid de Postgres.
            $shipmentId = '01a13700-1111-7111-8111-111111111111';
            $candidate = DeliveryCandidate::of($shipmentId, -16.5, -68.15, 10.0, 0.1, 'LP');
            $eta = Eta::at($departAt->modify('+10 minutes'), 10);
            $route = new PlannedRoute('01a13700-2222-7222-8222-222222222222', 'LP', [$candidate], [$shipmentId => $eta], null);

            return new RoutingResult([$route], []);
        }
    };

    $runner = new DailyPlanRunner(
        $runs,
        $pending,
        $fleet,
        $backend,
        $origin,
        $plans,
        app(RecordDomainEvents::class),
        app(Clock::class),
    );

    $date = PlanDate::fromString((new DateTimeImmutable('today'))->format('Y-m-d'));
    $run = AutoPlanRun::queue(AutoPlanRunId::generate(), $date, PlanStrategy::Optimization, app(Clock::class)->now());
    $runs->save($run);

    $runner->run($run->id(), $date, new AutoPlanParameters(
        PlanStrategy::Optimization,
        (new DateTimeImmutable('today 07:30:00'))->format(DateTimeInterface::ATOM),
        10.0,
        0.05,
        0.0,
    ));

    $reloaded = $runs->findById($run->id());
    expect($reloaded)->not->toBeNull()
        ->and($reloaded->status())->toBe(AutoPlanRunStatus::Completed)
        ->and($reloaded->trucksPlanned())->toBe(1)
        ->and($reloaded->stopsPlanned())->toBe(1);
});

it('DailyPlanRunner no reprocesa una corrida inexistente', function (): void {
    $runner = app(DailyPlanRunner::class);

    // Id que no existe: tolerante a reentregas de la cola, no hace nada ni lanza.
    $runner->run(
        AutoPlanRunId::generate(),
        PlanDate::fromString((new DateTimeImmutable('today'))->format('Y-m-d')),
        new AutoPlanParameters(
            PlanStrategy::Optimization,
            (new DateTimeImmutable('today 07:30:00'))->format(DateTimeInterface::ATOM),
            10.0,
            0.05,
            0.0,
        ),
    );

    expect(true)->toBeTrue();
});

// ──────────────────────────── VehicleIncidentReplanningDispatcher (outbox) ────────────────────────────

/**
 * Arma un mensaje del outbox con el evento y el payload dados, para alimentar al dispatcher.
 *
 * @param array<string, mixed> $payload
 */
function planningOutboxMessage(string $eventName, array $payload): OutboxMessage
{
    $now = new DateTimeImmutable('2026-10-15T10:00:00+00:00');

    return OutboxMessage::restore(
        OutboxMessageId::generate(),
        Uuid::generate(),
        'execution.vehicle_incident',
        (string) Str::uuid7(),
        $eventName,
        $payload,
        OutboxStatus::Pending,
        0,
        $now,
        $now,
        null,
        null,
    );
}

it('encola la replanificación ante un incidente MAYOR de camión', function (): void {
    $dispatcher = app(VehicleIncidentReplanningDispatcher::class);
    $shipment = (string) Str::uuid7();

    $dispatcher->dispatch(planningOutboxMessage('execution.vehicle_incident_reported', [
        'severity' => 'MAJOR',
        'reschedule_date' => '2026-10-16',
        'affected_shipment_ids' => [$shipment],
        'route_id' => (string) Str::uuid7(),
    ]));

    expect(DB::table('planning_shipment_replans')->where('shipment_id', $shipment)->exists())->toBeTrue();
});

it('ignora eventos ajenos, incidentes MENORES y payloads incompletos', function (): void {
    $dispatcher = app(VehicleIncidentReplanningDispatcher::class);

    // Otro evento: no hace nada.
    $dispatcher->dispatch(planningOutboxMessage('execution.other_event', ['severity' => 'MAJOR']));
    // Incidente menor: la ruta sigue, no replanifica.
    $dispatcher->dispatch(planningOutboxMessage('execution.vehicle_incident_reported', ['severity' => 'MINOR']));
    // Mayor pero sin fecha de reprogramación.
    $dispatcher->dispatch(planningOutboxMessage('execution.vehicle_incident_reported', [
        'severity' => 'MAJOR',
        'affected_shipment_ids' => [(string) Str::uuid7()],
    ]));
    // Mayor pero sin envíos afectados.
    $dispatcher->dispatch(planningOutboxMessage('execution.vehicle_incident_reported', [
        'severity' => 'MAJOR',
        'reschedule_date' => '2026-10-16',
        'affected_shipment_ids' => [],
    ]));

    expect(DB::table('planning_shipment_replans')->count())->toBe(0);
});

// ──────────────────────────── Adaptadores de Infrastructure (lecturas degradadas) ────────────────────────────

it('los adaptadores de lectura degradan sin datos cruzados sembrados', function (): void {
    $date = PlanDate::fromString((new DateTimeImmutable('today'))->format('Y-m-d'));

    // Sin envíos pendientes ni flota disponible: listas vacías (recorre el método y los loaders de requisitos).
    expect(app(EloquentPendingDeliveryProvider::class)->pendingFor($date))->toBe([]);
    expect(app(FleetAvailabilityProvider::class)->availableFor($date))->toBe([]);

    // Plan inexistente: el proveedor del problema de comparación devuelve null.
    expect(app(EloquentRouteComparisonProblemProvider::class)->forPlan(PlanId::generate()))->toBeNull();
});
