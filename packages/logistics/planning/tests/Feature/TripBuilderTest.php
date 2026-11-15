<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Logistics\Fleet\Database\Factories\VehicleFactory;
use Logistics\Network\Database\Factories\NodeFactory;
use Logistics\Planning\Application\Command\RemoveStopFromPlan\RemoveStopFromPlan;
use Logistics\Planning\Application\Query\ListTripCandidates\ListTripCandidates;
use Logistics\Planning\Application\Query\SuggestTripSequence\SuggestTripSequence;
use Logistics\Planning\Application\ReadModel\TripCandidatesView;
use Logistics\Planning\Application\ReadModel\TripSequenceSuggestionView;
use Logistics\Planning\Database\Factories\PlannedStopRecordFactory;
use Logistics\Planning\Database\Factories\PlanRecordFactory;
use Logistics\Planning\Infrastructure\Persistence\Eloquent\PlannedStopRecord;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Application\Query\QueryBus;
use Logistics\Shipments\Infrastructure\Persistence\Eloquent\Factories\ShipmentLineRecordFactory;
use Logistics\Shipments\Infrastructure\Persistence\Eloquent\Factories\ShipmentRecordFactory;
use Logistics\Shipments\Infrastructure\Persistence\Eloquent\ShipmentRecord;

/**
 * Armado de viajes y textos legibles del plan (pedido de la app 2026-11-15, secciones 1 y 2):
 * plan y ETA sin identificadores internos, candidatos por cercanía, orden sugerido, crear el plan sin
 * coordenadas respetando el orden recibido, quitar una parada y plan publicado de solo lectura (409).
 *
 * Día fijo lejano para no depender de "hoy". Se omite el middleware de puerta para probar el contrato de
 * los controllers y la cadena CQRS; el ruteador se fuerza a fallar (Http::fake) para ejercitar el respaldo
 * local determinista. La base del camión está en La Paz y los pedidos se alinean hacia el este de ella.
 */
const TRIP_BUILDER_DATE = '2031-03-12';

const TRIP_BUILDER_BASE_LAT = -16.5;

const TRIP_BUILDER_BASE_LNG = -68.15;

beforeEach(function (): void {
    Http::fake(['*' => Http::response(null, 500)]);
    $this->withoutMiddleware();
});

/**
 * Camión con base en un CD de La Paz. Devuelve el id del vehículo.
 */
function tripBuilderVehicle(int $capacityKg = 5000): string
{
    $node = NodeFactory::new()->distributionCenter()->create([
        'name' => 'CD El Alto',
        'lat' => TRIP_BUILDER_BASE_LAT,
        'lng' => TRIP_BUILDER_BASE_LNG,
        'department' => 'la_paz',
    ]);

    $vehicle = VehicleFactory::new()->homeBase((string) $node->getKey())->create([
        'status' => 'available',
        'capacity_kg' => $capacityKg,
        'capacity_m3' => 20.0,
    ]);

    return (string) $vehicle->getKey();
}

/**
 * Pedido recibido para el día del viaje, en La Paz, a `$eastDegrees` grados al este de la base.
 *
 * @param array<string, mixed> $attributes
 */
function tripBuilderShipment(string $reference, float $eastDegrees, array $attributes = []): ShipmentRecord
{
    return ShipmentRecordFactory::new()->create([
        'external_id' => $reference,
        'customer_name' => 'Cliente '.$reference,
        'address_text' => 'Av. Arce 2525, Sopocachi, La Paz',
        'address_reference' => null,
        'lat' => TRIP_BUILDER_BASE_LAT,
        'lng' => TRIP_BUILDER_BASE_LNG + $eastDegrees,
        'status' => 'RECEIVED',
        'delivery_date' => TRIP_BUILDER_DATE,
        'destination_department' => 'la_paz',
        ...$attributes,
    ]);
}

/**
 * Ids de los pedidos de una respuesta, en el orden devuelto.
 *
 * @return list<string>
 */
function tripBuilderCandidateIds(TripCandidatesView $view): array
{
    return array_map(static fn (array $item): string => (string) $item['shipment_id'], $view->itemsToArray());
}

// ───────────────────────── 1. Textos legibles en el plan (sin identificadores internos) ─────────────────────────

it('el plan y su ETA traen los datos legibles de cada parada y de dónde sale el camión', function (): void {
    $vehicleId = tripBuilderVehicle();
    $shipment = tripBuilderShipment('SO-900001', 0.01, [
        'delivery_window_start' => '09:00',
        'delivery_window_end' => '11:00',
    ]);
    ShipmentLineRecordFactory::new()->create(['shipment_id' => $shipment->getKey(), 'quantity' => 2]);

    $plan = PlanRecordFactory::new()->onDate(TRIP_BUILDER_DATE)->proposed()->create(['vehicle_id' => $vehicleId]);
    PlannedStopRecordFactory::new()->scheduled(1)->create([
        'plan_id' => $plan->getKey(),
        'shipment_id' => $shipment->getKey(),
    ]);

    $this->getJson("/api/v1/app/plans/{$plan->getKey()}")
        ->assertOk()
        ->assertJsonPath('data.origin_node.name', 'CD El Alto')
        ->assertJsonPath('data.distance_km', null)
        ->assertJsonPath('data.duration_minutes', null)
        ->assertJsonPath('data.stops.0.order_reference', 'SO-900001')
        ->assertJsonPath('data.stops.0.customer_name', 'Cliente SO-900001')
        ->assertJsonPath('data.stops.0.short_address', 'Av. Arce 2525, Sopocachi')
        ->assertJsonPath('data.stops.0.delivery_mode', 'home_delivery')
        ->assertJsonPath('data.stops.0.units_total', 2)
        ->assertJsonPath('data.stops.0.time_window.from', '2031-03-12T09:00:00-04:00')
        ->assertJsonPath('data.stops.0.time_window.to', '2031-03-12T11:00:00-04:00');

    $this->getJson("/api/v1/app/plans/{$plan->getKey()}/eta")
        ->assertOk()
        ->assertJsonPath('data.stops.0.order_reference', 'SO-900001')
        ->assertJsonPath('data.stops.0.short_address', 'Av. Arce 2525, Sopocachi')
        ->assertJsonPath('data.stops.0.units_total', 2)
        ->assertJsonPath('data.stops.0.time_window.from', '2031-03-12T09:00:00-04:00');
})->skip(env('DB_CONNECTION') !== 'pgsql', 'requiere PostgreSQL');

it('una parada cuyo envío no se puede leer conserva la forma, con los datos legibles en null', function (): void {
    $plan = PlanRecordFactory::new()->onDate(TRIP_BUILDER_DATE)->proposed()->withStops(1)->create();

    $this->getJson("/api/v1/app/plans/{$plan->getKey()}")
        ->assertOk()
        ->assertJsonPath('data.origin_node', null)
        ->assertJsonPath('data.stops.0.short_address', null)
        ->assertJsonPath('data.stops.0.delivery_mode', null)
        ->assertJsonPath('data.stops.0.time_window', null);
})->skip(env('DB_CONNECTION') !== 'pgsql', 'requiere PostgreSQL');

// ───────────────────────────────── 2.a Candidatos ordenados por cercanía ─────────────────────────────────

it('lista los candidatos planificables por cercanía a la base y recomienda el que está cerca', function (): void {
    $vehicleId = tripBuilderVehicle();
    $near = tripBuilderShipment('SO-NEAR', 0.003);
    $mid = tripBuilderShipment('SO-MID', 0.03);
    $far = tripBuilderShipment('SO-FAR', 0.07);

    // No son candidatos: otro día, otro departamento, ya entregado y ya puesto en un plan de ese día.
    tripBuilderShipment('SO-OTHER-DAY', 0.001, ['delivery_date' => '2031-03-13']);
    tripBuilderShipment('SO-OTHER-DEPT', 0.002, ['destination_department' => 'santa_cruz']);
    tripBuilderShipment('SO-DELIVERED', 0.002, ['status' => 'DELIVERED']);
    $planned = tripBuilderShipment('SO-PLANNED', 0.002);
    PlannedStopRecordFactory::new()->create([
        'plan_id' => PlanRecordFactory::new()->onDate(TRIP_BUILDER_DATE)->create()->getKey(),
        'shipment_id' => $planned->getKey(),
    ]);

    /** @var TripCandidatesView $view */
    $view = app(QueryBus::class)->ask(new ListTripCandidates(TRIP_BUILDER_DATE, $vehicleId));
    $items = $view->itemsToArray();

    expect(tripBuilderCandidateIds($view))->toBe([(string) $near->getKey(), (string) $mid->getKey(), (string) $far->getKey()])
        ->and($view->total)->toBe(3)
        ->and($view->anchor)->toBe(['kind' => 'node', 'name' => 'CD El Alto'])
        ->and($view->capacity['max_kg'])->toBe(5000.0)
        ->and($view->capacity['max_units'])->toBeNull()
        ->and($view->capacity['used_units'])->toBe(0)
        ->and($items[0]['order_reference'])->toBe('SO-NEAR')
        ->and($items[0]['short_address'])->toBe('Av. Arce 2525, Sopocachi')
        ->and($items[0]['fits'])->toBeTrue()
        ->and($items[0]['suggested'])->toBeTrue()
        ->and($items[0]['suggested_reason'])->toContain('CD El Alto')
        ->and($items[0]['distance_km_from_anchor'])->toBeLessThan(0.5)
        ->and($items[2]['suggested'])->toBeFalse()
        ->and($items[2]['suggested_reason'])->toBeNull();
})->skip(env('DB_CONNECTION') !== 'pgsql', 'requiere PostgreSQL');

it('con pedidos ya elegidos mide desde el último, los excluye y descuenta la capacidad', function (): void {
    $vehicleId = tripBuilderVehicle();
    $near = tripBuilderShipment('SO-NEAR', 0.003);
    $mid = tripBuilderShipment('SO-MID', 0.03);
    $far = tripBuilderShipment('SO-FAR', 0.07);
    $nextToFar = tripBuilderShipment('SO-NEXT', 0.0705);

    /** @var TripCandidatesView $view */
    $view = app(QueryBus::class)->ask(new ListTripCandidates(
        TRIP_BUILDER_DATE,
        $vehicleId,
        selectedShipmentIds: [(string) $far->getKey()],
    ));
    $items = $view->itemsToArray();

    expect(tripBuilderCandidateIds($view))->toBe([(string) $nextToFar->getKey(), (string) $mid->getKey(), (string) $near->getKey()])
        ->and($view->anchor)->toBe(['kind' => 'shipment', 'name' => 'SO-FAR'])
        // Un pedido sin líneas cuenta como un producto con el peso por defecto de la configuración.
        ->and($view->capacity['used_kg'])->toBe(10.0)
        ->and($items[0]['suggested'])->toBeTrue()
        ->and($items[0]['suggested_reason'])->toBe('En la misma cuadra que la parada 1.')
        ->and($items[1]['suggested'])->toBeFalse();
})->skip(env('DB_CONNECTION') !== 'pgsql', 'requiere PostgreSQL');

it('marca que no entra el pedido que supera el peso del camión o que no tiene ubicación', function (): void {
    $vehicleId = tripBuilderVehicle(capacityKg: 100);
    $heavy = tripBuilderShipment('SO-HEAVY', 0.003);
    ShipmentLineRecordFactory::new()->withMeasurements(500.0, 0.01)->create(['shipment_id' => $heavy->getKey(), 'quantity' => 1]);
    $unlocated = tripBuilderShipment('SO-NOWHERE', 0.0, ['lat' => null, 'lng' => null]);

    /** @var TripCandidatesView $view */
    $view = app(QueryBus::class)->ask(new ListTripCandidates(TRIP_BUILDER_DATE, $vehicleId));
    $byReference = [];
    foreach ($view->itemsToArray() as $item) {
        $byReference[(string) $item['order_reference']] = $item;
    }

    expect($byReference['SO-HEAVY']['fits'])->toBeFalse()
        ->and($byReference['SO-HEAVY']['fits_reason'])->toContain('peso')
        ->and($byReference['SO-HEAVY']['suggested'])->toBeFalse()
        ->and($byReference['SO-NOWHERE']['fits'])->toBeFalse()
        ->and($byReference['SO-NOWHERE']['fits_reason'])->toContain('ubicación')
        ->and($byReference['SO-NOWHERE']['lat'])->toBeNull()
        // Sin ubicación va al final de la lista.
        ->and(tripBuilderCandidateIds($view)[1])->toBe((string) $unlocated->getKey());
})->skip(env('DB_CONNECTION') !== 'pgsql', 'requiere PostgreSQL');

it('busca por pedido, pagina en la base y devuelve null si el camión no existe', function (): void {
    $vehicleId = tripBuilderVehicle();
    tripBuilderShipment('SO-NEAR', 0.003);
    $mid = tripBuilderShipment('SO-MID', 0.03);
    tripBuilderShipment('SO-FAR', 0.07);

    $bus = app(QueryBus::class);

    /** @var TripCandidatesView $searched */
    $searched = $bus->ask(new ListTripCandidates(TRIP_BUILDER_DATE, $vehicleId, search: 'so-mid'));
    /** @var TripCandidatesView $paged */
    $paged = $bus->ask(new ListTripCandidates(TRIP_BUILDER_DATE, $vehicleId, limit: 1, offset: 1));

    expect(tripBuilderCandidateIds($searched))->toBe([(string) $mid->getKey()])
        ->and(tripBuilderCandidateIds($paged))->toBe([(string) $mid->getKey()])
        ->and($paged->total)->toBe(3)
        ->and($bus->ask(new ListTripCandidates(TRIP_BUILDER_DATE, (string) Str::uuid7())))->toBeNull();
})->skip(env('DB_CONNECTION') !== 'pgsql', 'requiere PostgreSQL');

it('GET /plans/candidates responde con meta de lista, ancla y capacidad', function (): void {
    $vehicleId = tripBuilderVehicle();
    $near = tripBuilderShipment('SO-NEAR', 0.003);
    $far = tripBuilderShipment('SO-FAR', 0.07);

    $this->getJson('/api/v1/app/plans/candidates?date='.TRIP_BUILDER_DATE."&vehicle_id={$vehicleId}&selected={$far->getKey()}&page=1&per_page=10")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.shipment_id', (string) $near->getKey())
        ->assertJsonPath('meta.count', 1)
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('meta.anchor.kind', 'shipment')
        ->assertJsonPath('meta.capacity.max_units', null);

    $this->getJson('/api/v1/app/plans/candidates?date='.TRIP_BUILDER_DATE."&vehicle_id={$vehicleId}&selected=no-es-uuid")
        ->assertStatus(422);
})->skip(env('DB_CONNECTION') !== 'pgsql', 'requiere PostgreSQL')
    ->skip(fn (): bool => !Route::has('api.v1.app.planning.plans.candidates'), 'la ruta se registra al integrar routes/api/planning.php');

// ───────────────────────────── 2.b Orden sugerido (camino corto + ventanas) ─────────────────────────────

it('sugiere el camino más corto desde la base y avisa que cambió el orden', function (): void {
    $vehicleId = tripBuilderVehicle();
    $near = tripBuilderShipment('SO-NEAR', 0.003);
    $mid = tripBuilderShipment('SO-MID', 0.03);
    $far = tripBuilderShipment('SO-FAR', 0.07);

    /** @var TripSequenceSuggestionView $view */
    $view = app(QueryBus::class)->ask(new SuggestTripSequence(
        TRIP_BUILDER_DATE,
        $vehicleId,
        [(string) $far->getKey(), (string) $near->getKey(), (string) $mid->getKey()],
    ));

    expect($view->orderedShipmentIds)->toBe([(string) $near->getKey(), (string) $mid->getKey(), (string) $far->getKey()])
        ->and($view->changed)->toBeTrue()
        ->and($view->warnings)->toBe([])
        ->and($view->stops)->toHaveCount(3)
        ->and($view->stops[0]['sequence'])->toBe(1)
        ->and($view->stops[0]['within_window'])->toBeTrue()
        ->and($view->stops[0]['eta_at'])->toStartWith('2031-03-12T07:3')
        ->and($view->distanceKm)->toBeGreaterThan(7.0)
        ->and($view->durationMinutes)->toBeGreaterThan(30);
})->skip(env('DB_CONNECTION') !== 'pgsql', 'requiere PostgreSQL');

it('adelanta el pedido con hora elegida por el cliente para llegar dentro de su ventana', function (): void {
    $vehicleId = tripBuilderVehicle();
    $near = tripBuilderShipment('SO-NEAR', 0.003);
    // Sale 07:30 y la ventana cierra 07:45: pasando antes por el pedido cercano (10 min de entrega) no se llega.
    $mid = tripBuilderShipment('SO-MID', 0.03, ['delivery_window_start' => '07:30', 'delivery_window_end' => '07:45']);
    $far = tripBuilderShipment('SO-FAR', 0.07);

    /** @var TripSequenceSuggestionView $view */
    $view = app(QueryBus::class)->ask(new SuggestTripSequence(
        TRIP_BUILDER_DATE,
        $vehicleId,
        [(string) $near->getKey(), (string) $mid->getKey(), (string) $far->getKey()],
        '2031-03-12T07:30:00-04:00',
    ));

    expect($view->orderedShipmentIds)->toBe([(string) $mid->getKey(), (string) $near->getKey(), (string) $far->getKey()])
        ->and($view->changed)->toBeTrue()
        ->and($view->stops[0]['within_window'])->toBeTrue()
        ->and($view->warnings)->toBe([]);
})->skip(env('DB_CONNECTION') !== 'pgsql', 'requiere PostgreSQL');

it('avisa ventana perdida, sobrecarga, otro departamento y pedido sin ubicación, sin identificadores en el texto', function (): void {
    $vehicleId = tripBuilderVehicle(capacityKg: 100);
    $late = tripBuilderShipment('SO-LATE', 0.003, ['delivery_window_start' => '05:00', 'delivery_window_end' => '06:00']);
    $heavy = tripBuilderShipment('SO-HEAVY', 0.03, ['destination_department' => 'santa_cruz']);
    ShipmentLineRecordFactory::new()->withMeasurements(500.0, 0.01)->create(['shipment_id' => $heavy->getKey(), 'quantity' => 1]);
    $unlocated = tripBuilderShipment('SO-NOWHERE', 0.0, ['lat' => null, 'lng' => null]);

    $ids = [(string) $unlocated->getKey(), (string) $late->getKey(), (string) $heavy->getKey()];

    /** @var TripSequenceSuggestionView $view */
    $view = app(QueryBus::class)->ask(new SuggestTripSequence(TRIP_BUILDER_DATE, $vehicleId, $ids));

    $codes = array_map(static fn (array $warning): string => $warning['code'], $view->warnings);
    sort($codes);
    $details = implode(' ', array_map(static fn (array $warning): string => $warning['detail'], $view->warnings));
    $last = $view->stops[2];

    expect($codes)->toBe(['missing_location', 'out_of_department', 'over_capacity', 'window_missed'])
        ->and($last['shipment_id'])->toBe((string) $unlocated->getKey())
        ->and($last['eta_at'])->toBeNull()
        ->and($details)->toContain('SO-LATE')
        ->and($details)->toContain('Santa Cruz')
        ->and($details)->not->toContain((string) $late->getKey())
        ->and($details)->not->toContain((string) $heavy->getKey())
        ->and($details)->not->toContain((string) $unlocated->getKey());
})->skip(env('DB_CONNECTION') !== 'pgsql', 'requiere PostgreSQL');

it('POST /plans/suggest-sequence devuelve la forma del contrato y valida la entrada', function (): void {
    $vehicleId = tripBuilderVehicle();
    $near = tripBuilderShipment('SO-NEAR', 0.003);
    $far = tripBuilderShipment('SO-FAR', 0.07);

    $this->postJson('/api/v1/app/plans/suggest-sequence', [
        'date' => TRIP_BUILDER_DATE,
        'vehicle_id' => $vehicleId,
        'shipment_ids' => [(string) $far->getKey(), (string) $near->getKey()],
    ])->assertOk()
        ->assertJsonPath('data.ordered_shipment_ids.0', (string) $near->getKey())
        ->assertJsonPath('data.changed', true)
        ->assertJsonPath('data.stops.0.sequence', 1)
        ->assertJsonPath('data.warnings', []);

    $this->postJson('/api/v1/app/plans/suggest-sequence', [
        'date' => TRIP_BUILDER_DATE,
        'vehicle_id' => (string) Str::uuid7(),
        'shipment_ids' => [(string) $near->getKey()],
    ])->assertStatus(422);
})->skip(env('DB_CONNECTION') !== 'pgsql', 'requiere PostgreSQL')
    ->skip(fn (): bool => !Route::has('api.v1.app.planning.plans.suggest-sequence'), 'la ruta se registra al integrar routes/api/planning.php');

// ───────────────────────────────── 2.c Crear / guardar el viaje y quitar parada ─────────────────────────────────

it('POST /plans sin coordenadas toma la ubicación del envío, respeta el orden y guarda los datos legibles', function (): void {
    $vehicleId = tripBuilderVehicle();
    $near = tripBuilderShipment('SO-NEAR', 0.003);
    $far = tripBuilderShipment('SO-FAR', 0.07);

    $this->postJson('/api/v1/app/plans', [
        'plan_date' => TRIP_BUILDER_DATE,
        'vehicle_id' => $vehicleId,
        'depart_at' => '2031-03-12T07:30:00-04:00',
        // El orden recibido es el que queda: primero el lejano, aunque no sea el camino más corto.
        'stops' => [
            ['shipment_id' => (string) $far->getKey()],
            ['shipment_id' => (string) $near->getKey()],
        ],
    ])->assertStatus(201)
        ->assertJsonPath('data.status', 'PROPOSED')
        ->assertJsonPath('data.stops.0.shipment_id', (string) $far->getKey())
        ->assertJsonPath('data.stops.0.sequence', 1)
        ->assertJsonPath('data.stops.0.order_reference', 'SO-FAR')
        ->assertJsonPath('data.stops.1.shipment_id', (string) $near->getKey())
        ->assertJsonPath('data.origin_node.name', 'CD El Alto');

    expect(PlannedStopRecord::query()->where('shipment_id', $far->getKey())->value('customer_name'))->toBe('Cliente SO-FAR');
})->skip(env('DB_CONNECTION') !== 'pgsql', 'requiere PostgreSQL');

it('POST /plans con optimize reordena, y un envío sin ubicación responde 422 con el motivo', function (): void {
    $vehicleId = tripBuilderVehicle();
    $unlocated = tripBuilderShipment('SO-NOWHERE', 0.0, ['lat' => null, 'lng' => null]);

    $this->postJson('/api/v1/app/plans', [
        'plan_date' => TRIP_BUILDER_DATE,
        'vehicle_id' => $vehicleId,
        'depart_at' => '2031-03-12T07:30:00-04:00',
        'stops' => [['shipment_id' => (string) $unlocated->getKey()]],
    ])->assertStatus(422)
        ->assertJsonPath('code', 'business_rule_violation')
        ->assertJsonPath('detail', 'El pedido SO-NOWHERE no tiene ubicación en el mapa: corrija su dirección antes de sumarlo al viaje.');

    $this->postJson('/api/v1/app/plans', [
        'plan_date' => TRIP_BUILDER_DATE,
        'vehicle_id' => $vehicleId,
        'depart_at' => '2031-03-12T07:30:00-04:00',
        'optimize' => true,
        'stops' => [
            ['shipment_id' => (string) Str::uuid7(), 'latitude' => -16.4955, 'longitude' => -68.1336],
            ['shipment_id' => (string) Str::uuid7(), 'latitude' => -16.5089, 'longitude' => -68.1193],
        ],
    ])->assertStatus(201);
})->skip(env('DB_CONNECTION') !== 'pgsql', 'requiere PostgreSQL');

it('POST /plans para un camión que ya tiene plan ese día responde 409 con el plan existente', function (): void {
    $vehicleId = tripBuilderVehicle();
    $existing = PlanRecordFactory::new()->onDate(TRIP_BUILDER_DATE)->create(['vehicle_id' => $vehicleId]);
    $near = tripBuilderShipment('SO-NEAR', 0.003);

    $this->postJson('/api/v1/app/plans', [
        'plan_date' => TRIP_BUILDER_DATE,
        'vehicle_id' => $vehicleId,
        'depart_at' => '2031-03-12T07:30:00-04:00',
        'stops' => [['shipment_id' => (string) $near->getKey()]],
    ])->assertStatus(409)
        ->assertJsonPath('code', 'conflict')
        ->assertJsonPath('plan_id', (string) $existing->getKey());
})->skip(env('DB_CONNECTION') !== 'pgsql', 'requiere PostgreSQL');

it('POST /plans/{id}/stops completa los datos del envío y rechaza el que no tiene ubicación', function (): void {
    $plan = PlanRecordFactory::new()->onDate(TRIP_BUILDER_DATE)->create();
    $near = tripBuilderShipment('SO-NEAR', 0.003);
    $unlocated = tripBuilderShipment('SO-NOWHERE', 0.0, ['lat' => null, 'lng' => null]);

    $this->postJson("/api/v1/app/plans/{$plan->getKey()}/stops", ['shipment_id' => (string) $near->getKey()])
        ->assertOk()
        ->assertJsonPath('data.stops.0.order_reference', 'SO-NEAR');

    $this->postJson("/api/v1/app/plans/{$plan->getKey()}/stops", ['shipment_id' => (string) $unlocated->getKey()])
        ->assertStatus(422)
        ->assertJsonPath('code', 'business_rule_violation');
})->skip(env('DB_CONNECTION') !== 'pgsql', 'requiere PostgreSQL');

it('RemoveStopFromPlan quita la parada de un plan sin publicar y renumera la secuencia', function (): void {
    $plan = PlanRecordFactory::new()->onDate(TRIP_BUILDER_DATE)->proposed()->withStops(3)->create();
    /** @var list<string> $ids */
    $ids = PlannedStopRecord::query()->where('plan_id', $plan->getKey())->orderBy('sequence')->pluck('shipment_id')->all();

    app(CommandBus::class)->dispatch(new RemoveStopFromPlan((string) $plan->getKey(), $ids[0]));

    /** @var list<string> $remaining */
    $remaining = PlannedStopRecord::query()->where('plan_id', $plan->getKey())->orderBy('sequence')->pluck('shipment_id')->all();
    /** @var list<int> $sequences */
    $sequences = PlannedStopRecord::query()->where('plan_id', $plan->getKey())->orderBy('sequence')->pluck('sequence')->all();

    expect($remaining)->toBe([$ids[1], $ids[2]])
        ->and(array_map(intval(...), $sequences))->toBe([1, 2]);
})->skip(env('DB_CONNECTION') !== 'pgsql', 'requiere PostgreSQL');

it('DELETE /plans/{id}/stops/{shipment} quita la parada; 404 si no está y 409 si el plan está publicado', function (): void {
    $plan = PlanRecordFactory::new()->onDate(TRIP_BUILDER_DATE)->proposed()->withStops(2)->create();
    $shipmentId = (string) PlannedStopRecord::query()->where('plan_id', $plan->getKey())->orderBy('sequence')->value('shipment_id');

    $this->deleteJson("/api/v1/app/plans/{$plan->getKey()}/stops/{$shipmentId}")
        ->assertOk()
        ->assertJsonCount(1, 'data.stops')
        ->assertJsonPath('data.stops.0.sequence', 1);

    $this->deleteJson("/api/v1/app/plans/{$plan->getKey()}/stops/".Str::uuid7())
        ->assertStatus(404)
        ->assertJsonPath('code', 'not_found');

    $published = PlanRecordFactory::new()->onDate('2031-03-13')->published()->withStops(2)->create();
    $publishedShipment = (string) PlannedStopRecord::query()->where('plan_id', $published->getKey())->value('shipment_id');

    $this->deleteJson("/api/v1/app/plans/{$published->getKey()}/stops/{$publishedShipment}")
        ->assertStatus(409)
        ->assertJsonPath('code', 'conflict');

    expect(PlannedStopRecord::query()->where('plan_id', $published->getKey())->count())->toBe(2);
})->skip(env('DB_CONNECTION') !== 'pgsql', 'requiere PostgreSQL')
    ->skip(fn (): bool => !Route::has('api.v1.app.planning.plans.stops.remove'), 'la ruta se registra al integrar routes/api/planning.php');

it('un plan publicado es de solo lectura: sumar, reordenar y volver a publicar responden 409', function (): void {
    $plan = PlanRecordFactory::new()->onDate(TRIP_BUILDER_DATE)->published()->withStops(2)->create();
    /** @var list<string> $ids */
    $ids = PlannedStopRecord::query()->where('plan_id', $plan->getKey())->orderBy('sequence')->pluck('shipment_id')->all();

    $this->postJson("/api/v1/app/plans/{$plan->getKey()}/stops", ['shipment_id' => (string) Str::uuid7()])
        ->assertStatus(409)
        ->assertJsonPath('code', 'conflict');

    $this->putJson("/api/v1/app/plans/{$plan->getKey()}/sequence", ['ordered_shipment_ids' => array_reverse($ids)])
        ->assertStatus(409)
        ->assertJsonPath('code', 'conflict');

    $this->postJson("/api/v1/app/plans/{$plan->getKey()}/publish")
        ->assertStatus(409)
        ->assertJsonPath('code', 'conflict');

    // Nada cambió: mismas paradas, mismo orden.
    expect(PlannedStopRecord::query()->where('plan_id', $plan->getKey())->orderBy('sequence')->pluck('shipment_id')->all())->toBe($ids);
})->skip(env('DB_CONNECTION') !== 'pgsql', 'requiere PostgreSQL');
