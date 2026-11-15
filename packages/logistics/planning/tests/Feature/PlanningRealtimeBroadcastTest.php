<?php

declare(strict_types=1);

use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Logistics\Fleet\Database\Factories\VehicleFactory;
use Logistics\Network\Database\Factories\NodeFactory;
use Logistics\Network\Domain\ValueObject\Department;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Planning\Application\Service\DailyPlanRunner;
use Logistics\Planning\Database\Factories\PlannedStopRecordFactory;
use Logistics\Planning\Database\Factories\PlanRecordFactory;
use Logistics\Planning\Domain\Contract\AutoPlanRunRepository;
use Logistics\Planning\Domain\Contract\CrewShiftRulesProvider;
use Logistics\Planning\Domain\Contract\FleetAvailabilityProvider;
use Logistics\Planning\Domain\Contract\PendingDeliveryProvider;
use Logistics\Planning\Domain\Contract\PlanningOriginLocator;
use Logistics\Planning\Domain\Contract\PlanRepository;
use Logistics\Planning\Domain\Contract\ReplanBacklogRepository;
use Logistics\Planning\Domain\Contract\RoutingBackend;
use Logistics\Planning\Domain\Model\AutoPlanRun;
use Logistics\Planning\Domain\Model\DeliveryPlan;
use Logistics\Planning\Domain\ValueObject\AutoPlanParameters;
use Logistics\Planning\Domain\ValueObject\AutoPlanRunId;
use Logistics\Planning\Domain\ValueObject\CrewShiftWindow;
use Logistics\Planning\Domain\ValueObject\DeliveryCandidate;
use Logistics\Planning\Domain\ValueObject\Eta;
use Logistics\Planning\Domain\ValueObject\GeoPoint;
use Logistics\Planning\Domain\ValueObject\PlanDate;
use Logistics\Planning\Domain\ValueObject\PlanId;
use Logistics\Planning\Domain\ValueObject\PlannedRoute;
use Logistics\Planning\Domain\ValueObject\PlanStrategy;
use Logistics\Planning\Domain\ValueObject\RoutingResult;
use Logistics\Planning\Infrastructure\Persistence\Eloquent\PlannedStopRecord;
use Logistics\Planning\Infrastructure\Persistence\Eloquent\PlanRecord;
use Logistics\Planning\Infrastructure\Queue\GenerateDailyPlanJob;
use Logistics\Planning\Infrastructure\Realtime\PlanningRealtimeBroadcaster;
use Logistics\Planning\Presentation\Broadcasting\PlanningRunUpdated;
use Logistics\Planning\Presentation\Broadcasting\PlansUpdated;
use Logistics\Shared\Domain\Clock\Clock;
use Logistics\Shipments\Infrastructure\Persistence\Eloquent\ShipmentRecord;

// Avisos en tiempo real de Planificación: `plans.updated` (se guardó un plan del día) y
// `planning.run.updated` (cambió el estado de la corrida automática). Se verifica qué operación emite qué,
// por qué canales y con qué carga, que la señal sale después del commit y que sin transporte no hace nada.

beforeEach(function (): void {
    // Sin OSRM alcanzable el ruteo degrada a cercanía/haversine: el test corre offline y determinista.
    Http::fake(['*' => Http::response(null, 500)]);
    $this->withoutMiddleware();
});

/**
 * Activa un transporte de tiempo real y captura los dos eventos (sin encolar ni difundir nada). Se llama al
 * principio del test, antes de resolver cualquier repositorio.
 */
function prbRealtimeOn(): void
{
    config(['broadcasting.default' => 'pusher']);
    Event::fake([PlansUpdated::class, PlanningRunUpdated::class]);
}

/**
 * Nombres de canal (con el prefijo `private-`) de un evento.
 *
 * @return list<string>
 */
function prbChannels(PlansUpdated | PlanningRunUpdated $event): array
{
    return array_map(static fn (PrivateChannel $channel): string => $channel->name, $event->broadcastOn());
}

/**
 * Avisos `plans.updated` capturados hasta ahora, en orden.
 *
 * @return list<PlansUpdated>
 */
function prbPlanEvents(): array
{
    $events = [];
    foreach (Event::dispatched(PlansUpdated::class) as $arguments) {
        /** @var PlansUpdated $event */
        $event = $arguments[0];
        $events[] = $event;
    }

    return $events;
}

/**
 * Avisos `planning.run.updated` capturados hasta ahora, en orden.
 *
 * @return list<PlanningRunUpdated>
 */
function prbRunEvents(): array
{
    $events = [];
    foreach (Event::dispatched(PlanningRunUpdated::class) as $arguments) {
        /** @var PlanningRunUpdated $event */
        $event = $arguments[0];
        $events[] = $event;
    }

    return $events;
}

/** Camión con base en un centro de distribución de ese departamento; devuelve su id. */
function prbVehicleIn(Department $department): string
{
    $node = NodeFactory::new()->distributionCenter()->inDepartment($department)->create(['lat' => -16.5, 'lng' => -68.15]);

    return (string) VehicleFactory::new()->homeBase((string) $node->getKey())->create()->getKey();
}

function prbLoadPlan(string $planId): DeliveryPlan
{
    $plan = app(PlanRepository::class)->findById(PlanId::fromString($planId));
    expect($plan)->not->toBeNull();

    /** @var DeliveryPlan $plan */
    return $plan;
}

/**
 * Runner del auto-planificador con un backend falso que arma una ruta de una parada por cada camión dado.
 *
 * @param list<string> $vehicleIds
 */
function prbRunner(array $vehicleIds): DailyPlanRunner
{
    $backend = new class($vehicleIds) implements RoutingBackend
    {
        /** @param list<string> $vehicleIds */
        public function __construct(private readonly array $vehicleIds) {}

        public function route(
            DateTimeImmutable $departAt,
            ?GeoPoint $origin,
            array $candidates,
            array $vehicles,
            AutoPlanParameters $params,
        ): RoutingResult {
            $routes = [];
            foreach ($this->vehicleIds as $vehicleId) {
                $shipmentId = (string) Str::uuid7();
                $candidate = DeliveryCandidate::of($shipmentId, -16.5, -68.15, 10.0, 0.1, 'LP');
                $eta = Eta::at($departAt->modify('+10 minutes'), 10);
                $routes[] = new PlannedRoute($vehicleId, 'LP', [$candidate], [$shipmentId => $eta], null);
            }

            return new RoutingResult($routes, []);
        }
    };

    return new DailyPlanRunner(
        app(AutoPlanRunRepository::class),
        new class implements PendingDeliveryProvider
        {
            public function pendingFor(PlanDate $date): array
            {
                return [];
            }
        },
        new class implements FleetAvailabilityProvider
        {
            public function availableFor(PlanDate $date): array
            {
                return [];
            }
        },
        $backend,
        new class implements PlanningOriginLocator
        {
            public function locate(?string $originNodeId): ?GeoPoint
            {
                return null;
            }
        },
        app(PlanRepository::class),
        app(RecordDomainEvents::class),
        app(Clock::class),
        app(ReplanBacklogRepository::class),
        new class implements CrewShiftRulesProvider
        {
            public function windowFor(PlanDate $date): ?CrewShiftWindow
            {
                return null;
            }
        },
    );
}

function prbParameters(): AutoPlanParameters
{
    return new AutoPlanParameters(PlanStrategy::Optimization, '2026-12-01T07:30:00+00:00', 10.0, 0.05, 0.0);
}

/** Abre una corrida encolada para el 2026-12-01 y devuelve su id. */
function prbQueuedRun(): AutoPlanRunId
{
    $run = AutoPlanRun::queue(
        AutoPlanRunId::generate(),
        PlanDate::fromString('2026-12-01'),
        PlanStrategy::Optimization,
        new DateTimeImmutable('2026-11-30T22:00:00+00:00'),
    );
    app(AutoPlanRunRepository::class)->save($run);

    return $run->id();
}

// ──────────────────────────── Forma de los eventos ────────────────────────────

it('plans.updated viaja encolado por planning.all y por cada ciudad, solo con la señal', function (): void {
    $event = new PlansUpdated('2026-12-01', '01a13700-3333-7333-8333-333333333333', ['santa_cruz', 'la_paz', 'santa_cruz', 'No Válida']);

    expect($event)->toBeInstanceOf(ShouldBroadcast::class)
        ->and($event)->not->toBeInstanceOf(ShouldBroadcastNow::class)
        ->and($event->tries)->toBe(1)
        ->and($event->broadcastAs())->toBe('plans.updated')
        ->and(prbChannels($event))->toBe(['private-planning.all', 'private-planning.city.santa_cruz', 'private-planning.city.la_paz'])
        ->and($event->broadcastWith())->toBe(['plan_date' => '2026-12-01', 'plan_id' => '01a13700-3333-7333-8333-333333333333', 'deleted' => false])
        ->and($event->broadcastQueue())->toBe((string) config('observability.metrics.queues.notifications', 'notifications'));

    // Sin ciudades y con varios planes: solo el canal general y plan_id null.
    $many = new PlansUpdated('2026-12-01', null);

    expect(prbChannels($many))->toBe(['private-planning.all'])
        ->and($many->broadcastWith())->toBe(['plan_date' => '2026-12-01', 'plan_id' => null, 'deleted' => false]);
});

it('planning.run.updated lleva plan_date, strategy, status y la corrida completa bajo run', function (): void {
    $run = [
        'id' => '01a13700-4444-7444-8444-444444444444',
        'plan_date' => '2026-12-01',
        'strategy' => 'optimization',
        'status' => 'completed',
        'requested_at' => '2026-11-30T22:00:00+00:00',
        'completed_at' => '2026-11-30T22:03:00+00:00',
        'trucks_planned' => 4,
        'stops_planned' => 31,
        'unassigned_count' => 2,
        'failure_reason' => null,
    ];
    $event = new PlanningRunUpdated($run, ['cochabamba']);

    expect($event)->toBeInstanceOf(ShouldBroadcast::class)
        ->and($event)->not->toBeInstanceOf(ShouldBroadcastNow::class)
        ->and($event->tries)->toBe(1)
        ->and($event->broadcastAs())->toBe('planning.run.updated')
        ->and(prbChannels($event))->toBe(['private-planning.all', 'private-planning.city.cochabamba'])
        ->and($event->broadcastWith())->toBe([
            'plan_date' => '2026-12-01',
            'strategy' => 'optimization',
            'status' => 'completed',
            'run' => $run,
        ]);
});

// ──────────────────────────── Sin transporte ────────────────────────────

it('sin transporte de tiempo real (null o log) no emite nada y las operaciones siguen andando', function (string $driver): void {
    config(['broadcasting.default' => $driver]);
    Event::fake([PlansUpdated::class, PlanningRunUpdated::class]);
    Queue::fake();

    $date = (new DateTimeImmutable('today'))->format('Y-m-d');
    $this->postJson('/api/v1/app/plans', [
        'plan_date' => $date,
        'vehicle_id' => (string) Str::uuid7(),
        'depart_at' => (new DateTimeImmutable('today 07:30:00'))->format(DateTimeInterface::ATOM),
        'stops' => [
            ['shipment_id' => (string) Str::uuid7(), 'latitude' => -16.4955, 'longitude' => -68.1336],
            ['shipment_id' => (string) Str::uuid7(), 'latitude' => -16.5089, 'longitude' => -68.1193],
        ],
    ])->assertStatus(201);

    $this->postJson('/api/v1/app/plans/auto', ['plan_date' => $date, 'strategy' => 'optimization'])->assertStatus(202);

    Event::assertNotDispatched(PlansUpdated::class);
    Event::assertNotDispatched(PlanningRunUpdated::class);
})->with(['null', 'log']);

it('si encolar el aviso falla, el plan se guarda igual', function (): void {
    config(['broadcasting.default' => 'pusher']);

    $events = Mockery::mock(Dispatcher::class);
    $events->shouldReceive('dispatch')->once()->andThrow(new RuntimeException('cola caída'));
    app()->instance(PlanningRealtimeBroadcaster::class, new PlanningRealtimeBroadcaster($events, app('config')));

    $record = PlanRecordFactory::new()->onDate('2026-12-01')->proposed()->withStops(2)->create();
    $plan = prbLoadPlan((string) $record->getKey());
    $plan->publish();

    app(PlanRepository::class)->save($plan);

    expect(PlanRecord::query()->findOrFail($record->getKey())->status)->toBe('PUBLISHED');
});

it('con transporte configurado el aviso se ENCOLA en la cola de avisos, no se difunde en el request', function (): void {
    config(['broadcasting.default' => 'pusher']);
    Queue::fake();

    $record = PlanRecordFactory::new()->onDate('2026-12-01')->proposed()->withStops(2)->create();
    app(PlanRepository::class)->save(prbLoadPlan((string) $record->getKey()));

    Queue::assertPushedOn(
        (string) config('observability.metrics.queues.notifications', 'notifications'),
        BroadcastEvent::class,
        static fn (BroadcastEvent $job): bool => $job->event instanceof PlansUpdated
            && $job->event->planId === (string) $record->getKey(),
    );
});

// ──────────────────────────── plans.updated por operación ────────────────────────────

it('crear un plan avisa una vez con su fecha, su id y la ciudad de la base del camión', function (): void {
    prbRealtimeOn();
    $vehicleId = prbVehicleIn(Department::LaPaz);

    $planId = $this->postJson('/api/v1/app/plans', [
        'plan_date' => '2026-12-01',
        'vehicle_id' => $vehicleId,
        'depart_at' => '2026-12-01T11:30:00+00:00',
        'stops' => [
            ['shipment_id' => (string) Str::uuid7(), 'latitude' => -16.4955, 'longitude' => -68.1336],
            ['shipment_id' => (string) Str::uuid7(), 'latitude' => -16.5089, 'longitude' => -68.1193],
        ],
    ])->assertStatus(201)->json('data.id');

    $events = prbPlanEvents();

    expect($events)->toHaveCount(1)
        ->and($events[0]->broadcastWith())->toBe(['plan_date' => '2026-12-01', 'plan_id' => $planId, 'deleted' => false])
        ->and(prbChannels($events[0]))->toBe(['private-planning.all', 'private-planning.city.la_paz']);
});

it('sumar, reordenar y quitar paradas avisan una vez cada uno con el id del plan', function (): void {
    prbRealtimeOn();
    $plan = PlanRecordFactory::new()->onDate('2026-12-01')->withStops(2)->create();
    $planId = (string) $plan->getKey();

    $this->postJson("/api/v1/app/plans/{$planId}/stops", ['shipment_id' => (string) Str::uuid7()])->assertOk();
    expect(prbPlanEvents())->toHaveCount(1);

    /** @var list<string> $ids */
    $ids = PlannedStopRecord::query()->where('plan_id', $planId)->orderBy('sequence')->pluck('shipment_id')->all();

    $this->putJson("/api/v1/app/plans/{$planId}/sequence", ['ordered_shipment_ids' => array_reverse($ids)])->assertOk();
    expect(prbPlanEvents())->toHaveCount(2);

    $this->deleteJson("/api/v1/app/plans/{$planId}/stops/{$ids[0]}")->assertOk();
    $events = prbPlanEvents();

    expect($events)->toHaveCount(3);
    foreach ($events as $event) {
        expect($event->broadcastWith())->toBe(['plan_date' => '2026-12-01', 'plan_id' => $planId, 'deleted' => false]);
    }
});

it('publicar un plan avisa una vez', function (): void {
    prbRealtimeOn();
    $plan = PlanRecordFactory::new()->onDate('2026-12-01')->proposed()->withStops(2)->create();

    $this->postJson("/api/v1/app/plans/{$plan->getKey()}/publish")->assertOk();

    $events = prbPlanEvents();

    expect($events)->toHaveCount(1)
        ->and($events[0]->broadcastWith())->toBe(['plan_date' => '2026-12-01', 'plan_id' => (string) $plan->getKey(), 'deleted' => false]);
});

it('el plan avisa a la base del camión y al origen y destino de sus pedidos, incluida la parada recién quitada', function (): void {
    prbRealtimeOn();
    $vehicleId = prbVehicleIn(Department::SantaCruz);
    $shipment = ShipmentRecord::factory()->create([
        'origin_department' => 'cochabamba',
        'destination_department' => 'oruro',
    ]);

    $record = PlanRecordFactory::new()->onDate('2026-12-01')->create(['vehicle_id' => $vehicleId]);
    PlannedStopRecordFactory::new()->scheduled(1)->create(['plan_id' => $record->getKey(), 'shipment_id' => $shipment->getKey()]);
    PlannedStopRecordFactory::new()->scheduled(2)->create(['plan_id' => $record->getKey()]);

    $plan = prbLoadPlan((string) $record->getKey());
    $plan->removeStop((string) $shipment->getKey());
    app(PlanRepository::class)->save($plan);

    $events = prbPlanEvents();
    expect($events)->toHaveCount(1);

    $channels = prbChannels($events[0]);
    sort($channels);

    expect($channels)->toBe([
        'private-planning.all',
        'private-planning.city.cochabamba',
        'private-planning.city.oruro',
        'private-planning.city.santa_cruz',
    ]);

    // Guardado otra vez, ya sin esa parada: solo queda la ciudad del camión.
    app(PlanRepository::class)->save(prbLoadPlan((string) $record->getKey()));

    expect(prbChannels(prbPlanEvents()[1]))->toBe(['private-planning.all', 'private-planning.city.santa_cruz']);
});

it('varios planes del mismo día guardados en una transacción salen en UN aviso, después del commit', function (): void {
    prbRealtimeOn();
    $first = PlanRecordFactory::new()->onDate('2026-12-01')->proposed()->withStops(1)->create(['vehicle_id' => prbVehicleIn(Department::LaPaz)]);
    $second = PlanRecordFactory::new()->onDate('2026-12-01')->proposed()->withStops(1)->create(['vehicle_id' => prbVehicleIn(Department::Tarija)]);

    DB::transaction(function () use ($first, $second): void {
        app(PlanRepository::class)->save(prbLoadPlan((string) $first->getKey()));
        app(PlanRepository::class)->save(prbLoadPlan((string) $second->getKey()));

        // Dentro de la transacción todavía no salió nada.
        expect(prbPlanEvents())->toBe([]);
    });

    $events = prbPlanEvents();
    expect($events)->toHaveCount(1)
        ->and($events[0]->broadcastWith())->toBe(['plan_date' => '2026-12-01', 'plan_id' => null, 'deleted' => false]);

    $channels = prbChannels($events[0]);
    sort($channels);

    expect($channels)->toBe(['private-planning.all', 'private-planning.city.la_paz', 'private-planning.city.tarija']);
});

it('si la transacción se revierte no sale ningún aviso', function (): void {
    prbRealtimeOn();
    $record = PlanRecordFactory::new()->onDate('2026-12-01')->proposed()->withStops(1)->create();

    try {
        DB::transaction(function () use ($record): void {
            app(PlanRepository::class)->save(prbLoadPlan((string) $record->getKey()));

            throw new RuntimeException('falla posterior al guardado');
        });
    } catch (RuntimeException) {
        // Esperado: la transacción se revierte.
    }

    Event::assertNotDispatched(PlansUpdated::class);
});

// ──────────────────────────── planning.run.updated ────────────────────────────

it('disparar la corrida automática avisa queued a planning.all y a todas las ciudades, con la corrida completa', function (): void {
    prbRealtimeOn();
    Queue::fake();

    $this->postJson('/api/v1/app/plans/auto', ['plan_date' => '2026-12-01', 'strategy' => 'optimization'])->assertStatus(202);

    $events = prbRunEvents();
    expect($events)->toHaveCount(1);

    $payload = $events[0]->broadcastWith();
    $shown = $this->getJson('/api/v1/app/plans/auto?date=2026-12-01&strategy=optimization')->assertOk()->json('data');
    $channels = prbChannels($events[0]);

    expect($payload['plan_date'])->toBe('2026-12-01')
        ->and($payload['strategy'])->toBe('optimization')
        ->and($payload['status'])->toBe('queued')
        // `run` es el mismo objeto que devuelve GET /plans/auto: mismas claves, misma corrida.
        ->and(array_keys($payload['run']))->toBe(array_keys($shown))
        ->and($payload['run']['id'])->toBe($shown['id'])
        ->and($payload['run']['status'])->toBe($shown['status'])
        ->and($payload['run']['trucks_planned'])->toBe(0)
        ->and($payload['run']['completed_at'])->toBeNull()
        ->and($channels)->toHaveCount(1 + count(Department::cases()))
        ->and($channels)->toContain('private-planning.all')
        ->and($channels)->toContain('private-planning.city.santa_cruz')
        ->and($channels)->toContain('private-planning.city.la_paz');

    // Re-disparar el mismo día y modo es idempotente: no abre otra corrida ni avisa de nuevo.
    $this->postJson('/api/v1/app/plans/auto', ['plan_date' => '2026-12-01', 'strategy' => 'optimization'])->assertStatus(202);
    expect(prbRunEvents())->toHaveCount(1);
});

it('la corrida avisa processing al arrancar y completed con su resumen, y sus planes salen en un solo aviso', function (): void {
    prbRealtimeOn();
    $runner = prbRunner([prbVehicleIn(Department::LaPaz), prbVehicleIn(Department::Cochabamba)]);
    $runId = prbQueuedRun();

    // Lo mismo que hace el trabajo: PROCESSING confirmado antes de la transacción del cálculo.
    $runner->start($runId);
    expect(array_map(static fn (PlanningRunUpdated $event): string => $event->run['status'], prbRunEvents()))
        ->toBe(['queued', 'processing']);

    DB::transaction(function () use ($runner, $runId): void {
        $runner->run($runId, PlanDate::fromString('2026-12-01'), prbParameters());
    });

    $runEvents = prbRunEvents();
    expect(array_map(static fn (PlanningRunUpdated $event): string => $event->run['status'], $runEvents))
        ->toBe(['queued', 'processing', 'completed']);

    $completed = $runEvents[2]->broadcastWith();
    expect($completed['run']['id'])->toBe($runId->toString())
        ->and($completed['run']['trucks_planned'])->toBe(2)
        ->and($completed['run']['stops_planned'])->toBe(2)
        ->and($completed['run']['completed_at'])->not->toBeNull()
        ->and($completed['run']['failure_reason'])->toBeNull();

    $planEvents = prbPlanEvents();
    expect($planEvents)->toHaveCount(1)
        ->and($planEvents[0]->broadcastWith())->toBe(['plan_date' => '2026-12-01', 'plan_id' => null, 'deleted' => false]);

    $channels = prbChannels($planEvents[0]);
    sort($channels);

    expect($channels)->toBe(['private-planning.all', 'private-planning.city.cochabamba', 'private-planning.city.la_paz']);
});

it('agotados los reintentos del trabajo la corrida queda fallida y avisa failed con el motivo', function (): void {
    prbRealtimeOn();
    $runId = prbQueuedRun();
    app(DailyPlanRunner::class)->start($runId);

    $job = new GenerateDailyPlanJob($runId->toString(), '2026-12-01', prbParameters());
    $job->failed(new RuntimeException('el ruteador no responde'));
    // Una segunda llamada no repite el aviso ni pisa el motivo.
    $job->failed(new RuntimeException('otro motivo'));

    $events = prbRunEvents();
    $row = DB::table('planning_auto_plan_runs')->where('id', $runId->toString())->first();

    expect(array_map(static fn (PlanningRunUpdated $event): string => $event->run['status'], $events))
        ->toBe(['queued', 'processing', 'failed'])
        ->and($events[2]->broadcastWith()['run']['failure_reason'])->toBe('el ruteador no responde')
        ->and($row?->status)->toBe('failed')
        ->and($row?->failure_reason)->toBe('el ruteador no responde');
});
