<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Logistics\Fleet\Database\Factories\DriverFactory;
use Logistics\Fleet\Database\Factories\VehicleFactory;
use Logistics\Shared\Domain\ValueObject\Uuid;
use Logistics\Telemetry\Domain\ValueObject\AlertSeverity;
use Logistics\Telemetry\Domain\ValueObject\AlertType;
use Logistics\Telemetry\Infrastructure\Persistence\Eloquent\Factory\AlertFactory;
use Logistics\Telemetry\Infrastructure\Persistence\Eloquent\Model\AlertRecord;
use Logistics\Telemetry\Infrastructure\Persistence\Eloquent\Model\PingRecord;
use Logistics\Telemetry\Infrastructure\Persistence\Eloquent\Model\TripRecord;
use Logistics\Telemetry\Infrastructure\Persistence\Eloquent\Model\VehiclePositionRecord;

/**
 * Pedidos de la auditoría de la app (#12, #17, #20 y seguimiento por envío) contra el stack completo:
 *
 *  - ingesta por lote idempotente por lectura, tolerante al desorden, con resultado por ítem (202/207) y
 *    equivalente a mandar las lecturas de a una;
 *  - flota en vivo con placa/chofer, ETag → 304 y `?since=`;
 *  - alertas paginadas con meta estándar + `unacknowledged_total`;
 *  - migas de pan por envío (ventana a bordo), desvío de ruta y exceso sostenido (con su evento en el outbox),
 *    puente al seguimiento por envío y ETA dinámica con respaldo en línea recta.
 *
 * Se omite el middleware de puerta (patrón F) para ejercitar el contrato del controller y la cadena CQRS.
 */
beforeEach(function (): void {
    $this->withoutMiddleware();
    config()->set('logistics-telemetry.eta.osrm_host', '');
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

/** Instante relativo a ahora en la zona de la app, en ISO 8601. */
function tbtAt(string $modifier): string
{
    return (new DateTimeImmutable($modifier))->format(DATE_ATOM);
}

/** Fecha operativa de hoy (zona del horario laboral). */
function tbtToday(): string
{
    return (new DateTimeImmutable('now', new DateTimeZone((string) config('logistics-telemetry.working_hours.timezone'))))->format('Y-m-d');
}

/** Entrega (execution) de un envío en un vehículo, con su escaneo de carga. */
function tbtDelivery(string $shipmentId, string $vehicleId, string $status, ?string $loadedAt): string
{
    $deliveryId = Uuid::generate()->value;
    DB::table('execution_deliveries')->insert([
        'id' => $deliveryId,
        'shipment_id' => $shipmentId,
        'vehicle_id' => $vehicleId,
        'status' => $status,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    if ($loadedAt !== null) {
        tbtScan($deliveryId, 'LOAD', $loadedAt);
    }

    return $deliveryId;
}

function tbtScan(string $deliveryId, string $type, string $occurredAt): void
{
    DB::table('execution_scan_events')->insert([
        'id' => Uuid::generate()->value,
        'delivery_id' => $deliveryId,
        'type' => $type,
        'unit_id' => 'unit-1',
        'lat' => -16.5,
        'lng' => -68.15,
        'device_id' => 'dev-1',
        'user_id' => 'user-1',
        'occurred_at' => $occurredAt,
        'idempotency_key' => Uuid::generate()->value,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * @param list<array<string, mixed>> $pings
 */
function tbtPost(object $test, string $vehicleId, array $pings, ?string $driverId = 'driver-1'): TestResponse
{
    return $test->postJson('/api/v1/app/telemetry/pings', [
        'vehicle_id' => $vehicleId,
        'driver_id' => $driverId,
        'pings' => $pings,
    ]);
}

// --- #12 Ingesta por lote ------------------------------------------------------------------------------

it('ingests a batch idempotently per fix: a retried batch is reported as duplicate and adds nothing', function (): void {
    $vehicleId = Uuid::generate()->value;
    $pings = [
        ['fix_id' => 'f-1', 'lat' => -16.500, 'lng' => -68.150, 'recorded_at' => tbtAt('-3 minutes'), 'speed_kph' => 30.0],
        ['fix_id' => 'f-2', 'lat' => -16.501, 'lng' => -68.151, 'recorded_at' => tbtAt('-2 minutes'), 'speed_kph' => 30.0],
        ['fix_id' => 'f-3', 'lat' => -16.502, 'lng' => -68.152, 'recorded_at' => tbtAt('-1 minute'), 'speed_kph' => 30.0],
    ];

    tbtPost($this, $vehicleId, $pings)
        ->assertStatus(202)
        ->assertJsonPath('data.accepted_count', 3)
        ->assertJsonPath('data.duplicate_count', 0)
        ->assertJsonPath('data.results.0.status', 'accepted');

    $distance = (float) TripRecord::query()->where('vehicle_id', $vehicleId)->value('distance_m');

    tbtPost($this, $vehicleId, $pings)
        ->assertStatus(202)
        ->assertJsonPath('data.accepted_count', 0)
        ->assertJsonPath('data.duplicate_count', 3)
        ->assertJsonPath('data.results.2.status', 'duplicate');

    expect(PingRecord::query()->where('vehicle_id', $vehicleId)->count())->toBe(3)
        ->and((float) TripRecord::query()->where('vehicle_id', $vehicleId)->value('distance_m'))->toBe($distance);
});

it('deduplicates readings without fix id by device and recorded_at, also inside the same batch', function (): void {
    $vehicleId = Uuid::generate()->value;
    $at = tbtAt('-1 minute');
    $ping = ['device_id' => 'dev-9', 'lat' => -16.5, 'lng' => -68.15, 'recorded_at' => $at];

    tbtPost($this, $vehicleId, [$ping, $ping])
        ->assertStatus(202)
        ->assertJsonPath('data.accepted_count', 1)
        ->assertJsonPath('data.duplicate_count', 1);

    expect(PingRecord::query()->where('vehicle_id', $vehicleId)->count())->toBe(1);
});

it('produces the same pings, trip and live position as sending the readings one by one', function (): void {
    $single = Uuid::generate()->value;
    $batch = Uuid::generate()->value;
    $pings = [
        ['lat' => -16.500, 'lng' => -68.150, 'recorded_at' => tbtAt('-3 minutes'), 'speed_kph' => 20.0, 'device_id' => 'd'],
        ['lat' => -16.505, 'lng' => -68.150, 'recorded_at' => tbtAt('-2 minutes'), 'speed_kph' => 25.0, 'device_id' => 'd'],
        ['lat' => -16.510, 'lng' => -68.150, 'recorded_at' => tbtAt('-1 minute'), 'speed_kph' => 30.0, 'device_id' => 'd'],
    ];

    foreach ($pings as $ping) {
        tbtPost($this, $single, [$ping])->assertStatus(202);
    }
    // El lote llega desordenado: se ordena por recorded_at.
    tbtPost($this, $batch, [$pings[2], $pings[0], $pings[1]])->assertStatus(202);

    $tripOf = static fn (string $v): array => [
        round((float) TripRecord::query()->where('vehicle_id', $v)->value('distance_m'), 1),
        (int) TripRecord::query()->where('vehicle_id', $v)->value('ping_count'),
    ];
    $liveOf = static function (string $v): array {
        $p = VehiclePositionRecord::query()->findOrFail($v);

        return [$p->lat, $p->lng, $p->recorded_at->toIso8601String()];
    };

    expect(PingRecord::query()->where('vehicle_id', $batch)->count())->toBe(PingRecord::query()->where('vehicle_id', $single)->count())
        ->and($tripOf($batch))->toBe($tripOf($single))
        ->and($liveOf($batch))->toBe($liveOf($single));
});

it('answers 207 with per-item results when part of the batch is malformed', function (): void {
    $vehicleId = Uuid::generate()->value;

    tbtPost($this, $vehicleId, [
        ['lat' => -16.5, 'lng' => -68.15, 'recorded_at' => tbtAt('-2 minutes')],
        ['lat' => 123.0, 'lng' => -68.15, 'recorded_at' => tbtAt('-1 minute')],
    ])
        ->assertStatus(207)
        ->assertJsonPath('data.accepted_count', 1)
        ->assertJsonPath('data.rejected_count', 1)
        ->assertJsonPath('data.results.1.status', 'rejected');

    expect(PingRecord::query()->where('vehicle_id', $vehicleId)->count())->toBe(1);

    tbtPost($this, $vehicleId, [['lat' => 123.0, 'lng' => -68.15, 'recorded_at' => tbtAt('-1 minute')]])
        ->assertStatus(422);
});

it('tolerates out-of-order batches: an older batch is stored but does not move the live position back', function (): void {
    $vehicleId = Uuid::generate()->value;
    $newer = tbtAt('-1 minute');

    tbtPost($this, $vehicleId, [['lat' => -16.40, 'lng' => -68.10, 'recorded_at' => $newer, 'device_id' => 'd']])->assertStatus(202);
    tbtPost($this, $vehicleId, [['lat' => -16.60, 'lng' => -68.20, 'recorded_at' => tbtAt('-10 minutes'), 'device_id' => 'd']])->assertStatus(202);

    $live = VehiclePositionRecord::query()->findOrFail($vehicleId);

    expect(PingRecord::query()->where('vehicle_id', $vehicleId)->count())->toBe(2)
        ->and($live->lat)->toBe(-16.40)
        ->and($live->lng)->toBe(-68.10);
});

// --- #17 Flota en vivo ----------------------------------------------------------------------------------

it('enriches the live fleet with plate and driver name and supports ETag 304 and since', function (): void {
    $vehicle = VehicleFactory::new()->create(['plate' => '904UTL']);
    $driver = DriverFactory::new()->create(['name' => 'Juan Mamani']);
    $vehicleId = (string) $vehicle->getKey();

    tbtPost($this, $vehicleId, [['lat' => -16.5, 'lng' => -68.15, 'recorded_at' => tbtAt('-1 minute')]], (string) $driver->getKey())
        ->assertStatus(202);

    $first = $this->getJson('/api/v1/app/telemetry/fleet/live')->assertOk();
    $item = collect($first->json('data'))->firstWhere('vehicle_id', $vehicleId);
    $etag = $first->headers->get('ETag');

    expect($item['plate'])->toBe('904UTL')
        ->and($item['driver_name'])->toBe('Juan Mamani')
        ->and($etag)->not->toBeNull()
        ->and($first->json('meta.server_time'))->toBeString();

    $this->getJson('/api/v1/app/telemetry/fleet/live', ['If-None-Match' => (string) $etag])->assertStatus(304);

    $future = rawurlencode((new DateTimeImmutable('+1 hour'))->format(DATE_ATOM));
    $past = rawurlencode((new DateTimeImmutable('-1 hour'))->format(DATE_ATOM));

    $this->getJson('/api/v1/app/telemetry/fleet/live?since='.$future)->assertOk()->assertJsonCount(0, 'data');
    expect(collect($this->getJson('/api/v1/app/telemetry/fleet/live?since='.$past)->json('data'))->pluck('vehicle_id')->all())
        ->toContain($vehicleId);

    $this->getJson('/api/v1/app/telemetry/fleet/live?since=not-a-date')->assertStatus(422);
});

// --- #20 Alertas paginadas ------------------------------------------------------------------------------

it('paginates telemetry alerts with the standard meta and the unacknowledged total', function (): void {
    $vehicleId = Uuid::generate()->value;
    AlertFactory::new()->count(4)->ofType(AlertType::Speeding, AlertSeverity::Warning)->create(['vehicle_id' => $vehicleId]);
    AlertFactory::new()->acknowledged()->ofType(AlertType::Speeding, AlertSeverity::Warning)->create(['vehicle_id' => $vehicleId]);

    $this->getJson('/api/v1/app/telemetry/alerts?vehicle_id='.$vehicleId.'&per_page=2&page=1')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('meta.total', 5)
        ->assertJsonPath('meta.total_pages', 3)
        ->assertJsonPath('meta.next_page', 2)
        ->assertJsonPath('meta.unacknowledged_total', 4);

    $future = rawurlencode((new DateTimeImmutable('+1 day'))->format(DATE_ATOM));
    $this->getJson('/api/v1/app/telemetry/alerts?vehicle_id='.$vehicleId.'&from='.$future)
        ->assertOk()
        ->assertJsonPath('meta.total', 0);
});

// --- Seguimiento por envío ------------------------------------------------------------------------------

it('returns the breadcrumbs of a shipment only within its on-board window', function (): void {
    $vehicleId = Uuid::generate()->value;
    $shipmentId = Uuid::generate()->value;
    $deliveryId = tbtDelivery($shipmentId, $vehicleId, 'IN_TRANSIT', tbtAt('-60 minutes'));

    tbtPost($this, $vehicleId, [
        ['lat' => -16.40, 'lng' => -68.10, 'recorded_at' => tbtAt('-90 minutes'), 'device_id' => 'd'],
        ['lat' => -16.50, 'lng' => -68.150, 'recorded_at' => tbtAt('-50 minutes'), 'device_id' => 'd'],
        ['lat' => -16.51, 'lng' => -68.150, 'recorded_at' => tbtAt('-30 minutes'), 'device_id' => 'd'],
        ['lat' => -16.52, 'lng' => -68.150, 'recorded_at' => tbtAt('-10 minutes'), 'device_id' => 'd'],
    ])->assertStatus(202);

    $this->getJson("/api/v1/app/telemetry/shipments/{$shipmentId}/breadcrumbs")
        ->assertOk()
        ->assertJsonPath('data.window.open', true)
        ->assertJsonPath('data.points_total', 3)
        ->assertJsonPath('data.points.0.lat', -16.5);

    // Entregado hace 20 minutos: la ventana se cierra y la última lectura queda fuera.
    tbtScan($deliveryId, 'PROOF_OF_DELIVERY', tbtAt('-20 minutes'));
    DB::table('execution_deliveries')->where('id', $deliveryId)->update(['status' => 'DELIVERED']);

    $this->getJson("/api/v1/app/telemetry/shipments/{$shipmentId}/breadcrumbs")
        ->assertOk()
        ->assertJsonPath('data.window.open', false)
        ->assertJsonPath('data.points_total', 2);

    $this->getJson('/api/v1/app/telemetry/shipments/'.Uuid::generate()->value.'/breadcrumbs')->assertNotFound();
});

it('raises a route deviation alert with its outbox event when the loaded vehicle leaves the planned route', function (): void {
    config()->set('logistics-telemetry.deviation.max_distance_m', 300.0);
    config()->set('logistics-telemetry.deviation.min_seconds', 60);

    $vehicleId = Uuid::generate()->value;
    tbtDelivery(Uuid::generate()->value, $vehicleId, 'IN_TRANSIT', tbtAt('-30 minutes'));
    DB::table('planning_plans')->insert([
        'id' => Uuid::generate()->value,
        'plan_date' => tbtToday(),
        'vehicle_id' => $vehicleId,
        'status' => 'PUBLISHED',
        'route_geometry' => json_encode(['type' => 'LineString', 'coordinates' => [[-68.20, -16.50], [-68.10, -16.50]]]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Sobre la ruta: sin alerta.
    tbtPost($this, $vehicleId, [
        ['lat' => -16.500, 'lng' => -68.18, 'recorded_at' => tbtAt('-10 minutes'), 'speed_kph' => 30.0],
        ['lat' => -16.500, 'lng' => -68.17, 'recorded_at' => tbtAt('-9 minutes'), 'speed_kph' => 30.0],
    ])->assertStatus(202);
    expect(AlertRecord::query()->where('vehicle_id', $vehicleId)->where('type', 'route_deviation')->count())->toBe(0);

    // ~2,2 km fuera de la ruta durante 2 minutos.
    tbtPost($this, $vehicleId, [
        ['lat' => -16.520, 'lng' => -68.16, 'recorded_at' => tbtAt('-5 minutes'), 'speed_kph' => 30.0],
        ['lat' => -16.520, 'lng' => -68.15, 'recorded_at' => tbtAt('-4 minutes'), 'speed_kph' => 30.0],
        ['lat' => -16.520, 'lng' => -68.14, 'recorded_at' => tbtAt('-3 minutes'), 'speed_kph' => 30.0],
    ])->assertStatus(202);

    expect(AlertRecord::query()->where('vehicle_id', $vehicleId)->where('type', 'route_deviation')->count())->toBe(1)
        ->and(DB::table('outbox_messages')->where('event_name', 'telemetry.vehicle_route_deviated')->where('payload->vehicle_id', $vehicleId)->exists())->toBeTrue();
});

it('raises a speeding alert only when the excess is sustained', function (): void {
    config()->set('logistics-telemetry.speeding.limit_kph', 90.0);
    config()->set('logistics-telemetry.speeding.sustained_seconds', 20);

    $spike = Uuid::generate()->value;
    tbtPost($this, $spike, [
        ['lat' => -16.5, 'lng' => -68.150, 'recorded_at' => tbtAt('-60 seconds'), 'speed_kph' => 120.0],
        ['lat' => -16.5, 'lng' => -68.151, 'recorded_at' => tbtAt('-50 seconds'), 'speed_kph' => 50.0],
    ])->assertStatus(202);

    $sustained = Uuid::generate()->value;
    tbtPost($this, $sustained, [
        ['lat' => -16.5, 'lng' => -68.150, 'recorded_at' => tbtAt('-60 seconds'), 'speed_kph' => 100.0],
        ['lat' => -16.5, 'lng' => -68.152, 'recorded_at' => tbtAt('-50 seconds'), 'speed_kph' => 105.0],
    ])->assertStatus(202);
    // El exceso sigue en el lote siguiente: el estado persistido completa los 20 s.
    tbtPost($this, $sustained, [
        ['lat' => -16.5, 'lng' => -68.155, 'recorded_at' => tbtAt('-35 seconds'), 'speed_kph' => 110.0],
    ])->assertStatus(202);

    expect(AlertRecord::query()->where('vehicle_id', $spike)->where('type', 'speeding')->count())->toBe(0)
        ->and(AlertRecord::query()->where('vehicle_id', $sustained)->where('type', 'speeding')->count())->toBe(1)
        ->and(DB::table('outbox_messages')->where('event_name', 'telemetry.vehicle_speeding_sustained')->where('payload->vehicle_id', $sustained)->exists())->toBeTrue();
});

it('bridges the batch to per-shipment tracking with one position event carrying the shipments on board', function (): void {
    $vehicleId = Uuid::generate()->value;
    $shipmentId = Uuid::generate()->value;
    tbtDelivery($shipmentId, $vehicleId, 'LOADED', tbtAt('-10 minutes'));

    tbtPost($this, $vehicleId, [
        ['lat' => -16.50, 'lng' => -68.15, 'recorded_at' => tbtAt('-2 minutes')],
        ['lat' => -16.51, 'lng' => -68.15, 'recorded_at' => tbtAt('-1 minute')],
    ])->assertStatus(202);

    $events = DB::table('outbox_messages')
        ->where('event_name', 'telemetry.vehicle_position_reported')
        ->where('aggregate_id', $vehicleId)
        ->get();

    expect($events)->toHaveCount(1);
    $payload = json_decode((string) $events[0]->payload, true);
    expect($payload['shipment_ids'])->toBe([$shipmentId])
        ->and($payload['lat'])->toBe(-16.51);
});

it('computes a dynamic ETA per remaining stop with the straight-line fallback and stores it on refresh', function (): void {
    $vehicleId = Uuid::generate()->value;
    $routeId = Uuid::generate()->value;
    $first = Uuid::generate()->value;
    $second = Uuid::generate()->value;

    DB::table('execution_driver_routes')->insert([
        'id' => $routeId, 'vehicle_id' => $vehicleId, 'route_date' => tbtToday(), 'status' => 'IN_PROGRESS',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    foreach ([[$first, 1, -68.14], [$second, 2, -68.12]] as [$shipmentId, $sequence, $lng]) {
        DB::table('execution_driver_route_stops')->insert([
            'id' => Uuid::generate()->value, 'route_id' => $routeId, 'shipment_id' => $shipmentId, 'sequence' => $sequence,
            'lat' => -16.50, 'lng' => $lng, 'status' => 'PENDING', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
    tbtDelivery($second, $vehicleId, 'IN_TRANSIT', tbtAt('-30 minutes'));

    tbtPost($this, $vehicleId, [['lat' => -16.50, 'lng' => -68.15, 'recorded_at' => tbtAt('-1 minute'), 'speed_kph' => 20.0]])
        ->assertStatus(202);

    $response = $this->getJson("/api/v1/app/telemetry/vehicles/{$vehicleId}/etas")
        ->assertOk()
        ->assertJsonPath('data.traffic_aware', false)
        ->assertJsonPath('data.position_stale', false)
        ->assertJsonCount(2, 'data.stops')
        ->assertJsonPath('data.stops.0.method', 'straight_line');

    expect($response->json('data.stops.0.eta'))->toBeString()
        ->and($response->json('data.stops.1.remaining_distance_m'))->toBeGreaterThan($response->json('data.stops.0.remaining_distance_m'));

    $this->getJson("/api/v1/app/telemetry/shipments/{$second}/eta")
        ->assertOk()
        ->assertJsonCount(1, 'data.stops')
        ->assertJsonPath('data.stops.0.shipment_id', $second);

    $this->artisan('telemetry:refresh-etas', ['--vehicle' => $vehicleId])->assertSuccessful();

    expect(DB::table('telemetry_stop_etas')->where('vehicle_id', $vehicleId)->count())->toBe(2)
        ->and(DB::table('telemetry_stop_etas')->where('shipment_id', $first)->value('method'))->toBe('straight_line');
});
