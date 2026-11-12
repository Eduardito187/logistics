<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Logistics\Coverage\Domain\Contract\DepartmentLocator;
use Logistics\Fleet\Database\Factories\VehicleFactory;
use Logistics\Network\Database\Factories\NodeFactory;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Planning\Application\Service\DailyPlanRunner;
use Logistics\Planning\Domain\Contract\AutoPlanRunRepository;
use Logistics\Planning\Domain\Contract\CrewShiftRulesProvider;
use Logistics\Planning\Domain\Contract\FleetAvailabilityProvider;
use Logistics\Planning\Domain\Contract\PendingDeliveryProvider;
use Logistics\Planning\Domain\Contract\PlanningOriginLocator;
use Logistics\Planning\Domain\Contract\PlanRepository;
use Logistics\Planning\Domain\Contract\ReplanBacklogRepository;
use Logistics\Planning\Domain\Contract\RoutingBackend;
use Logistics\Planning\Domain\Model\AutoPlanRun;
use Logistics\Planning\Domain\ValueObject\AutoPlanParameters;
use Logistics\Planning\Domain\ValueObject\AutoPlanRunId;
use Logistics\Planning\Domain\ValueObject\CrewShiftWindow;
use Logistics\Planning\Domain\ValueObject\DeliveryCandidate;
use Logistics\Planning\Domain\ValueObject\Eta;
use Logistics\Planning\Domain\ValueObject\GeoPoint;
use Logistics\Planning\Domain\ValueObject\PlanDate;
use Logistics\Planning\Domain\ValueObject\PlannedRoute;
use Logistics\Planning\Domain\ValueObject\PlanStrategy;
use Logistics\Planning\Domain\ValueObject\RoutingResult;
use Logistics\Planning\Infrastructure\Adapter\EloquentPendingDeliveryProvider;
use Logistics\Planning\Infrastructure\Adapter\FleetVehicleAvailabilityProvider;
use Logistics\Planning\Infrastructure\Adapter\ShipmentLoadReader;
use Logistics\Shared\Domain\Clock\Clock;
use Logistics\Shipments\Infrastructure\Persistence\Eloquent\Factories\ShipmentLineRecordFactory;
use Logistics\Shipments\Infrastructure\Persistence\Eloquent\Factories\ShipmentRecordFactory;

// Correcciones de base (Ola 0) del auto-planificador: filtro por fecha prometida, consumo del backlog de
// replanificación, lecturas filtradas de requisitos/restricciones, ventana de turno de la tripulación y
// carga real (kg/m3) por línea con fallback a defaults. OSRM falseado: todo offline y determinista.

beforeEach(function (): void {
    Http::fake(['*' => Http::response(null, 500)]);
});

/**
 * Reloj fijo en la fecha/hora dada (el "hoy" del negocio para la regla de envíos sin fecha).
 */
function planningFixClock(string $moment): Clock
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

/**
 * Proveedor de pendientes REAL con el reloj dado.
 */
function planningFixPending(Clock $clock): EloquentPendingDeliveryProvider
{
    return new EloquentPendingDeliveryProvider(
        app(DepartmentLocator::class),
        app(ShipmentLoadReader::class),
        app(ReplanBacklogRepository::class),
        $clock,
    );
}

/**
 * Backend falso: arma UNA ruta con todos los candidatos recibidos, con las ETA (minutos) dadas por posición
 * (o 10 × posición si no se dan), y recuerda la salida y la cantidad de candidatos que recibió.
 *
 * @param list<int> $etaMinutes
 */
function planningFixBackend(array $etaMinutes = []): RoutingBackend
{
    return new class($etaMinutes) implements RoutingBackend
    {
        public ?DateTimeImmutable $seenDepartAt = null;

        public int $seenCandidates = 0;

        /** @param list<int> $etaMinutes */
        public function __construct(private readonly array $etaMinutes) {}

        public function route(
            DateTimeImmutable $departAt,
            ?GeoPoint $origin,
            array $candidates,
            array $vehicles,
            AutoPlanParameters $params,
        ): RoutingResult {
            $this->seenDepartAt = $departAt;
            $this->seenCandidates = count($candidates);
            if ($candidates === []) {
                return new RoutingResult([], []);
            }

            $etas = [];
            foreach ($candidates as $index => $candidate) {
                $minutes = $this->etaMinutes[$index] ?? 10 * ($index + 1);
                $etas[$candidate->shipmentId] = Eta::at($departAt->modify("+{$minutes} minutes"), $minutes);
            }

            // Id de camión en formato UUID: el plan se persiste en columnas uuid de Postgres.
            return new RoutingResult([new PlannedRoute('01a13700-2222-7222-8222-222222222222', 'LP', array_values($candidates), $etas, null)], []);
        }
    };
}

function planningFixShiftRules(?CrewShiftWindow $window): CrewShiftRulesProvider
{
    return new class($window) implements CrewShiftRulesProvider
    {
        public function __construct(private readonly ?CrewShiftWindow $window) {}

        public function windowFor(PlanDate $date): ?CrewShiftWindow
        {
            return $this->window;
        }
    };
}

function planningFixRunner(PendingDeliveryProvider $pending, RoutingBackend $backend, CrewShiftRulesProvider $shifts): DailyPlanRunner
{
    return new DailyPlanRunner(
        app(AutoPlanRunRepository::class),
        $pending,
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
        $shifts,
    );
}

/**
 * Abre y ejecuta una corrida del auto-planificador para el día, con la salida dada; devuelve la corrida.
 */
function planningFixRun(DailyPlanRunner $runner, PlanDate $date, string $departAt): AutoPlanRun
{
    $runs = app(AutoPlanRunRepository::class);
    $run = AutoPlanRun::queue(AutoPlanRunId::generate(), $date, PlanStrategy::Optimization, app(Clock::class)->now());
    $runs->save($run);

    $runner->run($run->id(), $date, new AutoPlanParameters(PlanStrategy::Optimization, $departAt, 10.0, 0.05, 0.0));

    $reloaded = $runs->findById($run->id());
    expect($reloaded)->not->toBeNull();

    /** @var AutoPlanRun $reloaded */
    return $reloaded;
}

/**
 * @param  list<DeliveryCandidate> $candidates
 * @return list<string>
 */
function planningFixIds(array $candidates): array
{
    $ids = array_map(static fn (DeliveryCandidate $candidate): string => $candidate->shipmentId, $candidates);
    sort($ids);

    return $ids;
}

// ──────────────────────────── 1. Filtro por fecha prometida ────────────────────────────

it('un plan del día X solo toma envíos prometidos para X (y, si X es hoy, los sin fecha y atrasados)', function (): void {
    $pending = planningFixPending(planningFixClock('2026-10-20 06:00:00'));
    $coords = ['status' => 'RECEIVED', 'lat' => -16.4955, 'lng' => -68.1336];

    $today = ShipmentRecordFactory::new()->withDeliverySlot('2026-10-20', '09:00', '12:00')->create($coords);
    $tomorrow = ShipmentRecordFactory::new()->withDeliverySlot('2026-10-21', '09:00', '12:00')->create($coords);
    $overdue = ShipmentRecordFactory::new()->withDeliverySlot('2026-10-19', '09:00', '12:00')->create($coords);
    $undated = ShipmentRecordFactory::new()->create($coords);
    // Otro estado: nunca entra por la regla de fecha.
    ShipmentRecordFactory::new()->withDeliverySlot('2026-10-20', '09:00', '12:00')->create([...$coords, 'status' => 'DELIVERED']);

    $forToday = planningFixIds($pending->pendingFor(PlanDate::fromString('2026-10-20')));
    $expectedToday = [(string) $today->getKey(), (string) $overdue->getKey(), (string) $undated->getKey()];
    sort($expectedToday);

    expect($forToday)->toBe($expectedToday)
        // Un plan futuro no se lleva los sin fecha ni los de otro día.
        ->and(planningFixIds($pending->pendingFor(PlanDate::fromString('2026-10-21'))))->toBe([(string) $tomorrow->getKey()])
        ->and($pending->pendingFor(PlanDate::fromString('2026-10-22')))->toBe([]);
});

// ──────────────────────────── 2. Backlog de replanificación consumido una vez ────────────────────────────

it('el backlog de replanificación entra como candidato y se consume una sola vez al generar el plan', function (): void {
    $date = PlanDate::fromString('2026-10-26');
    $backlog = app(ReplanBacklogRepository::class);

    // Envío afectado por un incidente: sigue en ejecución y su fecha prometida era otro día.
    $shipment = ShipmentRecordFactory::new()->withDeliverySlot('2026-10-25', '09:00', '12:00')->create([
        'status' => 'IN_EXECUTION',
        'lat' => -16.4955,
        'lng' => -68.1336,
    ]);
    $shipmentId = (string) $shipment->getKey();
    $routeId = '01a13700-3333-7333-8333-333333333333';
    $backlog->enqueue($shipmentId, $date, 'vehicle_incident', $routeId);

    $pending = planningFixPending(planningFixClock('2026-10-20 06:00:00'));
    expect(planningFixIds($pending->pendingFor($date)))->toBe([$shipmentId]);

    $backend = planningFixBackend();
    $first = planningFixRun(planningFixRunner($pending, $backend, planningFixShiftRules(null)), $date, '2026-10-26T07:30:00');

    expect($backend->seenCandidates)->toBe(1)
        ->and($first->stopsPlanned())->toBe(1)
        ->and($backlog->pendingShipmentIdsFor($date))->toBe([])
        ->and(DB::table('planning_shipment_replans')->where('shipment_id', $shipmentId)->whereNotNull('processed_at')->count())->toBe(1);

    // Segunda corrida del mismo día: ya consumido, no vuelve a entrar.
    $second = planningFixBackend();
    planningFixRun(planningFixRunner($pending, $second, planningFixShiftRules(null)), $date, '2026-10-26T07:30:00');
    expect($second->seenCandidates)->toBe(0);

    // Reentrega del MISMO hecho: no reabre. Un incidente nuevo (otra ruta) sí.
    $backlog->enqueue($shipmentId, $date, 'vehicle_incident', $routeId);
    expect($backlog->pendingShipmentIdsFor($date))->toBe([]);
    $backlog->enqueue($shipmentId, $date, 'vehicle_incident', '01a13700-4444-7444-8444-444444444444');
    expect($backlog->pendingShipmentIdsFor($date))->toBe([$shipmentId]);
});

it('un envío del backlog ya entregado no se replanifica', function (): void {
    $date = PlanDate::fromString('2026-10-26');
    $shipment = ShipmentRecordFactory::new()->create(['status' => 'DELIVERED', 'lat' => -16.4955, 'lng' => -68.1336]);
    app(ReplanBacklogRepository::class)->enqueue((string) $shipment->getKey(), $date, 'vehicle_incident', null);

    expect(planningFixPending(planningFixClock('2026-10-20 06:00:00'))->pendingFor($date))->toBe([]);
});

// ──────────────────────────── 3. Lecturas filtradas de requisitos y restricciones ────────────────────────────

it('lee requisitos de envío y restricciones de camión solo de los ids en alcance', function (): void {
    $date = PlanDate::fromString('2026-10-20');
    $shipment = ShipmentRecordFactory::new()->withDeliverySlot('2026-10-20', '09:00', '12:00')
        ->create(['status' => 'RECEIVED', 'lat' => -16.4955, 'lng' => -68.1336]);

    // Requisito del envío en alcance y otro de un envío ajeno (no debe leerse).
    DB::table('planning_shipment_requirements')->insert([
        ['shipment_id' => (string) $shipment->getKey(), 'load_type' => 'fragile', 'zone' => null, 'height_m' => null, 'weight_kg' => null, 'created_at' => now(), 'updated_at' => now()],
        ['shipment_id' => '01a13700-5555-7555-8555-555555555555', 'load_type' => 'cold', 'zone' => null, 'height_m' => null, 'weight_kg' => null, 'created_at' => now(), 'updated_at' => now()],
    ]);

    $node = NodeFactory::new()->distributionCenter()->create(['lat' => -16.5, 'lng' => -68.15]);
    $vehicle = VehicleFactory::new()->homeBase((string) $node->getKey())->create(['status' => 'available', 'capacity_kg' => 5000, 'capacity_m3' => 20.0]);
    DB::table('planning_vehicle_restrictions')->insert([
        ['vehicle_id' => (string) $vehicle->getKey(), 'allowed_load_types' => json_encode(['fragile']), 'allowed_zones' => null, 'forbidden_zones' => null, 'max_height_m' => null, 'max_weight_kg' => null, 'created_at' => now(), 'updated_at' => now()],
        ['vehicle_id' => '01a13700-6666-7666-8666-666666666666', 'allowed_load_types' => json_encode(['cold']), 'allowed_zones' => null, 'forbidden_zones' => null, 'max_height_m' => null, 'max_weight_kg' => null, 'created_at' => now(), 'updated_at' => now()],
    ]);

    DB::enableQueryLog();
    $candidates = planningFixPending(planningFixClock('2026-10-20 06:00:00'))->pendingFor($date);
    $specs = app(FleetVehicleAvailabilityProvider::class)->availableFor($date);
    $queries = array_column(DB::getQueryLog(), 'query');
    DB::disableQueryLog();

    $readsOf = static fn (string $table): array => array_values(array_filter(
        $queries,
        static fn (string $sql): bool => str_contains($sql, $table),
    ));

    expect($candidates)->toHaveCount(1)
        ->and($candidates[0]->requirement->loadType)->toBe('fragile')
        ->and($specs)->toHaveCount(1)
        ->and($specs[0]->vehicleId)->toBe((string) $vehicle->getKey());

    // Toda lectura de esas tablas va acotada con whereIn (nunca un SELECT sin filtro).
    foreach ([...$readsOf('planning_shipment_requirements'), ...$readsOf('planning_vehicle_restrictions')] as $sql) {
        expect(strtolower($sql))->toContain(' in (');
    }
    expect($readsOf('planning_shipment_requirements'))->not->toBe([])
        ->and($readsOf('planning_vehicle_restrictions'))->not->toBe([]);
});

// ──────────────────────────── 4. Ventana de turno de la tripulación ────────────────────────────

it('la salida no se planifica antes del inicio del turno y se quitan las paradas que exceden la ventana', function (): void {
    $date = PlanDate::fromString('2026-10-20');
    $coords = ['status' => 'RECEIVED', 'lat' => -16.4955, 'lng' => -68.1336];
    foreach (range(1, 3) as $ignored) {
        ShipmentRecordFactory::new()->withDeliverySlot('2026-10-20', '09:00', '18:00')->create($coords);
    }

    // Turno 08:00–12:00 (240 min desde las 08:00), jornada máxima 600: la 3.ª parada (ETA 300) excede.
    $backend = planningFixBackend([30, 200, 300]);
    $runner = planningFixRunner(
        planningFixPending(planningFixClock('2026-10-20 05:00:00')),
        $backend,
        planningFixShiftRules(CrewShiftWindow::of(8 * 60, 12 * 60, 600)),
    );

    $run = planningFixRun($runner, $date, '2026-10-20T06:00:00');

    expect($backend->seenDepartAt?->format('H:i'))->toBe('08:00')
        ->and($backend->seenCandidates)->toBe(3)
        ->and($run->trucksPlanned())->toBe(1)
        ->and($run->stopsPlanned())->toBe(2)
        ->and($run->unassignedCount())->toBe(1)
        ->and(DB::table('planning_plan_stops')->count())->toBe(2);
});

// ──────────────────────────── 5. Carga real (kg/m3) con fallback ────────────────────────────

it('sin medidas reales usa los defaults por producto y marca la carga como estimada', function (): void {
    $shipment = ShipmentRecordFactory::new()->create();
    $attributes = ['shipment_id' => $shipment->getKey(), 'quantity' => 3];
    if (Schema::hasColumn('shipment_lines', 'unit_weight_kg')) {
        $attributes['unit_weight_kg'] = null;
    }
    if (Schema::hasColumn('shipment_lines', 'unit_volume_m3')) {
        $attributes['unit_volume_m3'] = null;
    }
    ShipmentLineRecordFactory::new()->create($attributes);
    $withoutLines = ShipmentRecordFactory::new()->create();

    $loads = app(ShipmentLoadReader::class)->loadsFor([(string) $shipment->getKey(), (string) $withoutLines->getKey()]);

    // 3 productos × 10 kg / 0,05 m3 (defaults de config); sin líneas = un producto estimado.
    expect($loads[(string) $shipment->getKey()]['kg'])->toBe(30.0)
        ->and($loads[(string) $shipment->getKey()]['m3'])->toEqualWithDelta(0.15, 0.000001)
        ->and($loads[(string) $shipment->getKey()]['estimated'])->toBeTrue()
        ->and($loads[(string) $withoutLines->getKey()])->toBe(['kg' => 10.0, 'm3' => 0.05, 'estimated' => true]);
});

it('con medidas reales por línea suma cantidad × medida y completa con defaults solo lo que falta', function (): void {
    if (!Schema::hasColumn('shipment_lines', 'unit_weight_kg') || !Schema::hasColumn('shipment_lines', 'unit_volume_m3')) {
        $this->markTestSkipped('shipment_lines todavía no tiene unit_weight_kg/unit_volume_m3 (migración de Envíos pendiente).');
    }
    $withSource = Schema::hasColumn('shipment_lines', 'measurements_source');

    // Envío con medidas reales completas: 2 × 4,5 kg + 1 × 20 kg = 29 kg; 2 × 0,02 + 1 × 0,3 = 0,34 m3.
    $real = ShipmentRecordFactory::new()->create(['status' => 'RECEIVED', 'lat' => -16.4955, 'lng' => -68.1336]);
    ShipmentLineRecordFactory::new()->create([
        'shipment_id' => $real->getKey(), 'quantity' => 2, 'unit_weight_kg' => 4.5, 'unit_volume_m3' => 0.02,
        ...($withSource ? ['measurements_source' => 'product'] : []),
    ]);
    ShipmentLineRecordFactory::new()->create([
        'shipment_id' => $real->getKey(), 'quantity' => 1, 'unit_weight_kg' => 20.0, 'unit_volume_m3' => 0.3,
        ...($withSource ? ['measurements_source' => 'product'] : []),
    ]);

    // Envío mixto: una línea real (1 × 7 kg / 0,1 m3) y otra sin medidas (2 × defaults 10 kg / 0,05 m3).
    $mixed = ShipmentRecordFactory::new()->create(['status' => 'RECEIVED', 'lat' => -16.4955, 'lng' => -68.1336]);
    ShipmentLineRecordFactory::new()->create([
        'shipment_id' => $mixed->getKey(), 'quantity' => 1, 'unit_weight_kg' => 7.0, 'unit_volume_m3' => 0.1,
    ]);
    ShipmentLineRecordFactory::new()->create([
        'shipment_id' => $mixed->getKey(), 'quantity' => 2, 'unit_weight_kg' => null, 'unit_volume_m3' => null,
    ]);

    $loads = app(ShipmentLoadReader::class)->loadsFor([(string) $real->getKey(), (string) $mixed->getKey()]);

    expect($loads[(string) $real->getKey()]['kg'])->toEqualWithDelta(29.0, 0.0001)
        ->and($loads[(string) $real->getKey()]['m3'])->toEqualWithDelta(0.34, 0.0001)
        ->and($loads[(string) $real->getKey()]['estimated'])->toBeFalse()
        ->and($loads[(string) $mixed->getKey()]['kg'])->toEqualWithDelta(27.0, 0.0001)
        ->and($loads[(string) $mixed->getKey()]['m3'])->toEqualWithDelta(0.2, 0.0001)
        ->and($loads[(string) $mixed->getKey()]['estimated'])->toBeTrue();

    // El candidato del planificador expone la carga real y la marca de estimada.
    $candidates = planningFixPending(planningFixClock('2026-10-20 06:00:00'))->pendingFor(PlanDate::fromString('2026-10-20'));
    $byId = [];
    foreach ($candidates as $candidate) {
        $byId[$candidate->shipmentId] = $candidate;
    }

    expect($byId[(string) $real->getKey()]->weightKg)->toEqualWithDelta(29.0, 0.0001)
        ->and($byId[(string) $real->getKey()]->loadEstimated)->toBeFalse()
        ->and($byId[(string) $mixed->getKey()]->loadEstimated)->toBeTrue();
});
