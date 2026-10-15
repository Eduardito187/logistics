<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Telemetry\Application\Command\AggregateStreetTraffic\AggregateStreetTraffic;
use Logistics\Telemetry\Domain\ValueObject\GeoPoint;
use Logistics\Telemetry\Domain\ValueObject\MatchedSegment;
use Logistics\Telemetry\Infrastructure\Persistence\Eloquent\Factory\PingFactory;
use Logistics\Telemetry\Infrastructure\Persistence\Eloquent\Model\StreetStatsRecord;
use Logistics\Telemetry\Infrastructure\Routing\OsrmMapMatcher;

/**
 * Cobertura de la analítica de calles (worker en segundo plano): el adaptador de map-matching contra OSRM
 * (OsrmMapMatcher) y el handler de agregación (AggregateStreetTrafficHandler). Patrón G del brief: NUNCA
 * se hace HTTP real; se usa Http::fake devolviendo la forma que el adaptador espera del servicio /match de
 * OSRM (code Ok + matchings con geometry.coordinates y legs.annotation.nodes/speed). Se ejercita el
 * happy-path y los fallbacks a vacío cuando OSRM cae o responde mal. Corre contra logistics_test
 * (RefreshDatabase); se marca skip sin PostgreSQL.
 */
beforeEach(function (): void {
    // Host determinista para el adaptador; Http::fake intercepta igual, pero así no se toca la red real.
    config()->set('logistics-telemetry.streets.osrm_host', 'http://osrm.test');
    config()->set('logistics-telemetry.streets.osrm_timeout', 5);
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

/**
 * Respuesta /match de OSRM con un matching de un leg: tres nodos OSM y dos velocidades (una por arista),
 * alineados con tres vértices de geometría. Produce dos MatchedSegment.
 *
 * @return array<string, mixed>
 */
function fakeOsrmMatchBody(): array
{
    return [
        'code' => 'Ok',
        'matchings' => [
            [
                'geometry' => [
                    'coordinates' => [
                        [-68.150, -16.500],
                        [-68.151, -16.501],
                        [-68.152, -16.502],
                    ],
                ],
                'legs' => [
                    [
                        'annotation' => [
                            'nodes' => [111, 222, 333],
                            'speed' => [10.0, 12.0],
                        ],
                    ],
                ],
            ],
        ],
    ];
}

it('mapea una traza GPS a segmentos de calle cuando OSRM responde Ok', function (): void {
    Http::fake(['*' => Http::response(fakeOsrmMatchBody(), 200)]);

    $matcher = new OsrmMapMatcher;

    $points = [
        GeoPoint::of(-16.500, -68.150),
        GeoPoint::of(-16.501, -68.151),
        GeoPoint::of(-16.502, -68.152),
    ];
    $timestamps = [
        (new DateTimeImmutable('2026-10-13T10:00:00-04:00'))->getTimestamp(),
        (new DateTimeImmutable('2026-10-13T10:01:00-04:00'))->getTimestamp(),
        (new DateTimeImmutable('2026-10-13T10:02:00-04:00'))->getTimestamp(),
    ];

    $segments = $matcher->match($points, $timestamps);

    // Tres nodos → dos aristas → dos segmentos, cada uno un MatchedSegment con velocidad en km/h (> 0).
    expect($segments)->toHaveCount(2)
        ->and($segments[0])->toBeInstanceOf(MatchedSegment::class)
        ->and($segments[0]->speedKph)->toBeGreaterThan(0.0)
        ->and($segments[0]->segmentKey)->not->toBe('');
});

it('degrada a vacío con menos de dos puntos sin llamar a OSRM', function (): void {
    Http::fake(['*' => Http::response(fakeOsrmMatchBody(), 200)]);

    $matcher = new OsrmMapMatcher;

    expect($matcher->match([GeoPoint::of(-16.5, -68.15)], [0]))->toBe([]);
    Http::assertNothingSent();
});

it('degrada a vacío cuando OSRM responde error HTTP', function (): void {
    Http::fake(['*' => Http::response(null, 500)]);

    $matcher = new OsrmMapMatcher;

    $segments = $matcher->match(
        [GeoPoint::of(-16.500, -68.150), GeoPoint::of(-16.501, -68.151)],
        [1_760_000_000, 1_760_000_060],
    );

    expect($segments)->toBe([]);
});

it('degrada a vacío cuando OSRM devuelve un code distinto de Ok', function (): void {
    Http::fake(['*' => Http::response(['code' => 'NoMatch'], 200)]);

    $matcher = new OsrmMapMatcher;

    $segments = $matcher->match(
        [GeoPoint::of(-16.500, -68.150), GeoPoint::of(-16.501, -68.151)],
        [1_760_000_000, 1_760_000_060],
    );

    expect($segments)->toBe([]);
});

it('agrega el tráfico de un día materializando StreetSegmentStats vía el CommandBus', function (): void {
    Http::fake(['*' => Http::response(fakeOsrmMatchBody(), 200)]);

    $vehicleId = '0192aaaa-bbbb-7ccc-8ddd-00000000a001';

    // Tres pings contiguos del mismo vehículo en el día a agregar (traza de más de un punto).
    PingFactory::new()->forVehicleAt($vehicleId, new DateTimeImmutable('2026-10-13 10:00:00'))->create();
    PingFactory::new()->forVehicleAt($vehicleId, new DateTimeImmutable('2026-10-13 10:01:00'))->create();
    PingFactory::new()->forVehicleAt($vehicleId, new DateTimeImmutable('2026-10-13 10:02:00'))->create();

    /** @var CommandBus $commands */
    $commands = app(CommandBus::class);
    $commands->dispatch(new AggregateStreetTraffic($vehicleId, '2026-10-13'));

    // El matcher falso devuelve dos aristas: deben quedar dos segmentos de calle con sus muestras.
    expect(StreetStatsRecord::query()->count())->toBe(2);
});

it('agrega todos los vehículos del día cuando no se pasa vehicleId', function (): void {
    Http::fake(['*' => Http::response(fakeOsrmMatchBody(), 200)]);

    $vehicleId = '0192aaaa-bbbb-7ccc-8ddd-00000000a002';
    PingFactory::new()->forVehicleAt($vehicleId, new DateTimeImmutable('2026-10-13 09:00:00'))->create();
    PingFactory::new()->forVehicleAt($vehicleId, new DateTimeImmutable('2026-10-13 09:01:00'))->create();

    /** @var CommandBus $commands */
    $commands = app(CommandBus::class);
    $commands->dispatch(new AggregateStreetTraffic(null, '2026-10-13'));

    expect(StreetStatsRecord::query()->count())->toBeGreaterThan(0);
});
