<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Logistics\Planning\Application\Command\AssignShipmentToVehicle\AssignShipmentToVehicle;
use Logistics\Planning\Application\Command\GeneratePlanProposal\GeneratePlanProposal;
use Logistics\Planning\Application\Command\GeneratePlanProposal\ProposalStopInput;
use Logistics\Planning\Application\Command\PublishPlan\PublishPlan;
use Logistics\Planning\Application\Command\ReassignShipmentToVehicle\ReassignShipmentToVehicle;
use Logistics\Planning\Application\Command\ReorderStops\ReorderStops;
use Logistics\Planning\Application\Query\GetDailyPlanRun\GetDailyPlanRun;
use Logistics\Planning\Application\Query\GetPlan\GetPlan;
use Logistics\Planning\Application\Query\GetRouteQuality\GetRouteQuality;
use Logistics\Planning\Application\Query\ListPlansByDate\ListPlansByDate;
use Logistics\Planning\Application\ReadModel\AutoPlanRunView;
use Logistics\Planning\Application\ReadModel\PlanView;
use Logistics\Planning\Application\ReadModel\RouteQualityView;
use Logistics\Planning\Database\Factories\PlanRecordFactory;
use Logistics\Planning\Infrastructure\Persistence\Eloquent\PlannedStopRecord;
use Logistics\Planning\Infrastructure\Persistence\Eloquent\PlanRecord;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Application\Query\QueryBus;

// Ejercita los handlers de consulta (ReadModels) y de comando (dominio + repositorio + mapper + outbox)
// del contexto planificación por los buses reales, con filas sembradas por la factory. El motor de rutas
// (OSRM) se fuerza al fallback por vecino más cercano con Http::fake, para no depender de la red.

beforeEach(function (): void {
    // Sin OSRM alcanzable el optimizador degrada al orden por cercanía; así el test corre offline.
    Http::fake(['*' => Http::response(null, 500)]);
    $this->commands = app(CommandBus::class);
    $this->queries = app(QueryBus::class);
});

it('GetPlan devuelve un PlanView del plan sembrado', function (): void {
    $plan = PlanRecordFactory::new()->proposed()->withStops(3)->create();

    /** @var PlanView|null $view */
    $view = $this->queries->ask(new GetPlan((string) $plan->getKey()));

    expect($view)->toBeInstanceOf(PlanView::class)
        ->and($view->id)->toBe((string) $plan->getKey())
        ->and($view->status)->toBe('PROPOSED')
        ->and($view->stops)->toHaveCount(3)
        ->and($view->toArray()['stops'])->toHaveCount(3);
});

it('GetPlan devuelve null cuando el plan no existe', function (): void {
    expect($this->queries->ask(new GetPlan((string) Str::uuid7())))->toBeNull();
});

it('ListPlansByDate lista los planes del día', function (): void {
    $date = (new DateTimeImmutable('today'))->format('Y-m-d');
    PlanRecordFactory::new()->onDate($date)->withStops(2)->create();
    PlanRecordFactory::new()->onDate($date)->proposed()->withStops(2)->create();

    /** @var list<PlanView> $views */
    $views = $this->queries->ask(new ListPlansByDate($date));

    expect($views)->toHaveCount(2)
        ->and($views[0])->toBeInstanceOf(PlanView::class);
});

it('GetRouteQuality arma la vista de calidad del plan', function (): void {
    $plan = PlanRecordFactory::new()->proposed()->withStops(3)->create();

    /** @var RouteQualityView|null $view */
    $view = $this->queries->ask(new GetRouteQuality((string) $plan->getKey()));

    expect($view)->toBeInstanceOf(RouteQualityView::class)
        ->and($view->planId)->toBe((string) $plan->getKey())
        ->and($view->stopCount)->toBe(3);

    $array = $view->toArray();
    expect($array)->toHaveKey('distance_meters')
        ->and($array)->toHaveKey('duration_minutes')
        ->and($array)->toHaveKey('occupancy_kg_pct');
});

it('GetRouteQuality devuelve null cuando el plan no existe', function (): void {
    expect($this->queries->ask(new GetRouteQuality((string) Str::uuid7())))->toBeNull();
});

it('GetDailyPlanRun devuelve la última corrida del auto-planificador', function (): void {
    DB::table('planning_auto_plan_runs')->insert([
        'id' => (string) Str::uuid7(),
        'plan_date' => (new DateTimeImmutable('today'))->format('Y-m-d'),
        'strategy' => 'optimization',
        'status' => 'completed',
        'requested_at' => now(),
        'completed_at' => now(),
        'trucks_planned' => 3,
        'stops_planned' => 12,
        'unassigned_count' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    /** @var AutoPlanRunView|null $view */
    $view = $this->queries->ask(new GetDailyPlanRun(
        (new DateTimeImmutable('today'))->format('Y-m-d'),
        'optimization',
    ));

    expect($view)->toBeInstanceOf(AutoPlanRunView::class)
        ->and($view->status)->toBe('completed')
        ->and($view->trucksPlanned)->toBe(3)
        ->and($view->toArray()['stops_planned'])->toBe(12);
});

it('GetDailyPlanRun devuelve null cuando no hubo corrida', function (): void {
    $view = $this->queries->ask(new GetDailyPlanRun('2099-01-01', 'slots'));

    expect($view)->toBeNull();
});

it('GeneratePlanProposal crea y propone un plan nuevo', function (): void {
    // Paradas sin cliente (traspasos): exentas de la regla geográfica, para no acoplar a coberturas.
    $this->commands->dispatch(new GeneratePlanProposal(
        planDate: (new DateTimeImmutable('today'))->format('Y-m-d'),
        vehicleId: (string) Str::uuid7(),
        departAt: (new DateTimeImmutable('today 07:30:00'))->format(DateTimeInterface::ATOM),
        stops: [
            new ProposalStopInput((string) Str::uuid7(), -16.4955, -68.1336),
            new ProposalStopInput((string) Str::uuid7(), -16.5089, -68.1193),
            new ProposalStopInput((string) Str::uuid7(), -16.5235, -68.0869),
        ],
    ));

    $plan = PlanRecord::query()->firstOrFail();
    expect($plan->status)->toBe('PROPOSED')
        ->and(PlannedStopRecord::query()->where('plan_id', $plan->id)->count())->toBe(3);
});

it('AssignShipmentToVehicle agrega un envío a un plan en borrador', function (): void {
    $plan = PlanRecordFactory::new()->create();
    $shipmentId = (string) Str::uuid7();

    $this->commands->dispatch(new AssignShipmentToVehicle(
        planId: (string) $plan->getKey(),
        shipmentId: $shipmentId,
    ));

    expect(PlannedStopRecord::query()
        ->where('plan_id', $plan->getKey())
        ->where('shipment_id', $shipmentId)
        ->exists())->toBeTrue();
});

it('PublishPlan publica un plan propuesto', function (): void {
    $plan = PlanRecordFactory::new()->proposed()->withStops(2)->create();

    $this->commands->dispatch(new PublishPlan((string) $plan->getKey()));

    expect(PlanRecord::query()->whereKey($plan->getKey())->value('status'))->toBe('PUBLISHED');
});

it('ReorderStops reordena la secuencia del plan', function (): void {
    $plan = PlanRecordFactory::new()->withStops(3)->create();
    /** @var list<string> $ids */
    $ids = PlannedStopRecord::query()
        ->where('plan_id', $plan->getKey())
        ->orderBy('sequence')
        ->pluck('shipment_id')
        ->all();
    $reversed = array_reverse($ids);

    $this->commands->dispatch(new ReorderStops(
        planId: (string) $plan->getKey(),
        orderedShipmentIds: $reversed,
    ));

    $first = PlannedStopRecord::query()
        ->where('plan_id', $plan->getKey())
        ->where('sequence', 1)
        ->value('shipment_id');

    expect($first)->toBe($reversed[0]);
});

it('ReassignShipmentToVehicle mueve un envío entre planes del mismo día', function (): void {
    $date = (new DateTimeImmutable('today'))->format('Y-m-d');
    $from = PlanRecordFactory::new()->onDate($date)->withStops(2)->create();
    $to = PlanRecordFactory::new()->onDate($date)->create();

    /** @var string $shipmentId */
    $shipmentId = PlannedStopRecord::query()
        ->where('plan_id', $from->getKey())
        ->orderBy('sequence')
        ->value('shipment_id');

    $this->commands->dispatch(new ReassignShipmentToVehicle(
        shipmentId: $shipmentId,
        fromPlanId: (string) $from->getKey(),
        toPlanId: (string) $to->getKey(),
    ));

    expect(PlannedStopRecord::query()
        ->where('plan_id', $to->getKey())
        ->where('shipment_id', $shipmentId)
        ->exists())->toBeTrue()
        ->and(PlannedStopRecord::query()
            ->where('plan_id', $from->getKey())
            ->where('shipment_id', $shipmentId)
            ->exists())->toBeFalse();
});
