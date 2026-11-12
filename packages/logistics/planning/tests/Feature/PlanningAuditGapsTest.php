<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Logistics\Fleet\Database\Factories\CrewFactory;
use Logistics\Fleet\Database\Factories\DriverFactory;
use Logistics\Fleet\Database\Factories\VehicleFactory;
use Logistics\Network\Database\Factories\NodeFactory;
use Logistics\Planning\Application\Query\GetPlan\GetPlan;
use Logistics\Planning\Application\Query\ListPlansByDate\ListPlansByDate;
use Logistics\Planning\Application\ReadModel\PlanView;
use Logistics\Planning\Database\Factories\PlanRecordFactory;
use Logistics\Planning\Domain\ValueObject\PlanDate;
use Logistics\Planning\Domain\ValueObject\VehicleCapacitySpec;
use Logistics\Planning\Infrastructure\Adapter\FleetVehicleAvailabilityProvider;
use Logistics\Shared\Application\Query\QueryBus;
use Logistics\Shared\Domain\Clock\Clock;

// Brechas de auditoría del contexto Planificación: camión no apto fuera del auto-planificador, disparo
// nocturno programado (comando idempotente) y placa/chofer en el modelo de lectura del plan, en lote.

beforeEach(function (): void {
    Http::fake(['*' => Http::response(null, 500)]);
});

/**
 * Reloj fijo en el instante dado (ISO 8601 con zona).
 */
function auditGapsClock(string $moment): Clock
{
    return new class($moment) implements Clock
    {
        public function __construct(private readonly string $moment) {}

        public function now(): DateTimeImmutable
        {
            return new DateTimeImmutable($this->moment);
        }
    };
}

// ──────────────────────────── 1. Camión no apto ────────────────────────────

it('el auto-planificador no ofrece camiones no aptos (unfit_since vigente) y sí los aptos', function (): void {
    if (!Schema::hasColumn('fleet_vehicles', 'unfit_since')) {
        test()->markTestSkipped('fleet_vehicles.unfit_since todavía no existe (migración de Flota).');
    }

    $today = now('America/La_Paz')->toDateString();
    $planDate = PlanDate::fromString(now('America/La_Paz')->addDay()->toDateString());
    $node = NodeFactory::new()->distributionCenter()->create(['lat' => -16.5, 'lng' => -68.15]);
    $base = ['status' => 'available', 'capacity_kg' => 5000, 'capacity_m3' => 20.0];

    $fit = VehicleFactory::new()->homeBase((string) $node->getKey())->create($base);
    $unfitToday = VehicleFactory::new()->homeBase((string) $node->getKey())->create($base + ['unfit_since' => $today]);
    $unfitYesterday = VehicleFactory::new()->homeBase((string) $node->getKey())
        ->create($base + ['unfit_since' => now('America/La_Paz')->subDay()->toDateString()]);
    // Marcado no apto a futuro (después del día del plan): todavía sirve para ese día.
    $unfitLater = VehicleFactory::new()->homeBase((string) $node->getKey())
        ->create($base + ['unfit_since' => now('America/La_Paz')->addDays(5)->toDateString()]);

    $ids = array_map(
        static fn (VehicleCapacitySpec $spec): string => $spec->vehicleId,
        app(FleetVehicleAvailabilityProvider::class)->availableFor($planDate),
    );

    expect($ids)->toContain((string) $fit->getKey())
        ->and($ids)->toContain((string) $unfitLater->getKey())
        ->and($ids)->not->toContain((string) $unfitToday->getKey())
        ->and($ids)->not->toContain((string) $unfitYesterday->getKey());
});

// ──────────────────────────── 2. Disparo nocturno ────────────────────────────

it('el comando nocturno planifica mañana en La Paz y es idempotente por día y modo', function (): void {
    Queue::fake();
    // 02:00 UTC del 13 = 22:00 del 12 en La Paz: "mañana" es el 13, no el 14.
    app()->instance(Clock::class, auditGapsClock('2026-11-13T02:00:00+00:00'));

    $this->artisan('logistics:planning:auto-plan')->assertSuccessful();
    $this->artisan('logistics:planning:auto-plan')->assertSuccessful();

    $runs = DB::table('planning_auto_plan_runs')->get();

    expect($runs)->toHaveCount(1)
        ->and(substr((string) $runs[0]->plan_date, 0, 10))->toBe('2026-11-13')
        ->and($runs[0]->strategy)->toBe('optimization');
});

it('el comando nocturno acepta --date y --strategy y rechaza una fecha inválida', function (): void {
    Queue::fake();

    $this->artisan('logistics:planning:auto-plan', ['--date' => '2026-12-01', '--strategy' => 'slots'])->assertSuccessful();
    $this->artisan('logistics:planning:auto-plan', ['--date' => '01/12/2026'])->assertFailed();

    expect(DB::table('planning_auto_plan_runs')->where('plan_date', '2026-12-01')->where('strategy', 'slots')->count())->toBe(1);
});

it('la corrida nocturna está agendada en el scheduler', function (): void {
    // schedule:list arranca el kernel de consola, que carga routes/console.php.
    $this->artisan('schedule:list')
        ->expectsOutputToContain('logistics:planning:auto-plan')
        ->assertSuccessful();
});

// ──────────────────────────── 3. Placa y chofer del plan ────────────────────────────

it('el plan expone la placa del camión y el chofer de la tripulación del día', function (): void {
    $vehicle = VehicleFactory::new()->create(['plate' => '2456XYZ']);
    $driver = DriverFactory::new()->create(['name' => 'Juan Mamani']);
    CrewFactory::new()->forVehicle((string) $vehicle->getKey())->forDriver((string) $driver->getKey())
        ->assembledFor('2026-11-20')->create();
    $plan = PlanRecordFactory::new()->onDate('2026-11-20')->create(['vehicle_id' => (string) $vehicle->getKey()]);

    /** @var PlanView $view */
    $view = app(QueryBus::class)->ask(new GetPlan((string) $plan->getKey()));

    expect($view->plate)->toBe('2456XYZ')
        ->and($view->driverName)->toBe('Juan Mamani')
        ->and($view->toArray())->toHaveKeys(['plate', 'driver_name']);
});

it('sin tripulación el chofer queda null y la placa se resuelve igual, en lote y sin N+1', function (): void {
    for ($created = 0; $created < 5; $created++) {
        $vehicle = VehicleFactory::new()->create();
        PlanRecordFactory::new()->onDate('2026-11-21')->create(['vehicle_id' => (string) $vehicle->getKey()]);
    }

    DB::enableQueryLog();
    /** @var list<PlanView> $views */
    $views = app(QueryBus::class)->ask(new ListPlansByDate('2026-11-21'));
    $queries = array_column(DB::getQueryLog(), 'query');
    DB::disableQueryLog();

    $fleetReads = array_filter(
        $queries,
        static fn (string $sql): bool => str_contains($sql, 'fleet_vehicles') || str_contains($sql, 'fleet_crews'),
    );

    expect($views)->toHaveCount(5)
        ->and(array_filter($views, static fn (PlanView $view): bool => $view->plate === null))->toBe([])
        ->and(array_filter($views, static fn (PlanView $view): bool => $view->driverName !== null))->toBe([])
        // Una lectura de placas y una de tripulaciones para los 5 planes (no una por plan).
        ->and(count($fleetReads))->toBeLessThanOrEqual(2);
});
