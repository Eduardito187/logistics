<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Logistics\Telemetry\Application\Contract\RerouteGeometryCache;
use Logistics\Telemetry\Application\Service\RerouteGeometryCalculator;
use Logistics\Telemetry\Domain\Contract\RoadGeometryEstimator;
use Logistics\Telemetry\Domain\ValueObject\GeoPoint;
use Logistics\Telemetry\Infrastructure\Routing\OsrmRoadGeometryEstimator;
use Tests\TestCase;

// Geometría del reruteo (posición actual → próxima parada) cuando el camión está FUERA de ruta: la lectura no
// pega a OSRM (usa lo cacheado o la línea recta), el comando programado la calcula con OSRM y la cachea, y el
// estimador OSRM degrada con gracia. Se arranca la app (TestCase) para `config()` y `Http::fake`.
uses(TestCase::class);

const RG_VEHICLE = 'veh-reroute-1';

/** Posición actual del camión (desviado). */
const RG_POSITION = ['lat' => -16.5000, 'lng' => -68.1500];

/** Próxima parada pendiente. */
const RG_NEXT_STOP = ['lat' => -16.5100, 'lng' => -68.1300];

/** Caché de geometría en memoria para el test (sin Valkey). */
function rgCache(?array $seed = null): RerouteGeometryCache
{
    return new class($seed) implements RerouteGeometryCache
    {
        /** @var array<string, list<array{lat: float, lng: float}>> */
        private array $store = [];

        public int $forgotten = 0;

        /** @param list<array{lat: float, lng: float}>|null $seed */
        public function __construct(?array $seed)
        {
            if ($seed !== null) {
                $this->store[RG_VEHICLE] = $seed;
            }
        }

        public function get(string $vehicleId): ?array
        {
            return $this->store[$vehicleId] ?? null;
        }

        public function put(string $vehicleId, array $geometry): void
        {
            $this->store[$vehicleId] = $geometry;
        }

        public function forget(string $vehicleId): void
        {
            unset($this->store[$vehicleId]);
            $this->forgotten++;
        }
    };
}

/** Estimador vial que siempre devuelve una polilínea fija (simula a OSRM respondiendo). */
function rgRoadEstimator(?array $geometry): RoadGeometryEstimator
{
    return new class($geometry) implements RoadGeometryEstimator
    {
        /** @param list<array{lat: float, lng: float}>|null $geometry */
        public function __construct(private ?array $geometry) {}

        public function geometry(GeoPoint $from, GeoPoint $to): ?array
        {
            return $this->geometry;
        }
    };
}

it('en lectura, fuera de ruta sin nada cacheado devuelve la línea recta de dos puntos', function (): void {
    $calculator = new RerouteGeometryCalculator(rgCache());

    $geometry = $calculator->forRead(RG_VEHICLE, true, RG_POSITION, RG_NEXT_STOP);

    expect($geometry)->toBe([RG_POSITION, RG_NEXT_STOP]);
});

it('en lectura, fuera de ruta con geometría cacheada devuelve la cacheada (no la recta)', function (): void {
    $cached = [RG_POSITION, ['lat' => -16.5050, 'lng' => -68.1400], RG_NEXT_STOP];
    $calculator = new RerouteGeometryCalculator(rgCache($cached));

    expect($calculator->forRead(RG_VEHICLE, true, RG_POSITION, RG_NEXT_STOP))->toBe($cached);
});

it('en lectura, sin estar fuera de ruta o sin próxima parada no hay geometría', function (): void {
    $calculator = new RerouteGeometryCalculator(rgCache());

    expect($calculator->forRead(RG_VEHICLE, false, RG_POSITION, RG_NEXT_STOP))->toBeNull()
        ->and($calculator->forRead(RG_VEHICLE, true, RG_POSITION, null))->toBeNull()
        ->and($calculator->forRead(RG_VEHICLE, true, null, RG_NEXT_STOP))->toBeNull();
});

it('el refresco fuera de ruta cachea la geometría vial de OSRM', function (): void {
    $cache = rgCache();
    $road = [RG_POSITION, ['lat' => -16.5075, 'lng' => -68.1350], RG_NEXT_STOP];
    $calculator = new RerouteGeometryCalculator($cache);

    $calculator->refresh(RG_VEHICLE, true, RG_POSITION, RG_NEXT_STOP, rgRoadEstimator($road));

    expect($cache->get(RG_VEHICLE))->toBe($road);
});

it('el refresco fuera de ruta cachea la línea recta cuando OSRM no responde', function (): void {
    $cache = rgCache();
    $calculator = new RerouteGeometryCalculator($cache);

    $calculator->refresh(RG_VEHICLE, true, RG_POSITION, RG_NEXT_STOP, rgRoadEstimator(null));

    expect($cache->get(RG_VEHICLE))->toBe([RG_POSITION, RG_NEXT_STOP]);
});

it('el refresco sin estar fuera de ruta limpia la geometría cacheada', function (): void {
    $cache = rgCache([RG_POSITION, RG_NEXT_STOP]);
    $calculator = new RerouteGeometryCalculator($cache);

    $calculator->refresh(RG_VEHICLE, false, RG_POSITION, RG_NEXT_STOP, rgRoadEstimator(null));

    expect($cache->get(RG_VEHICLE))->toBeNull()
        ->and($cache->forgotten)->toBe(1);
});

it('toma la primera parada restante que tenga coordenadas', function (): void {
    $stops = [
        ['lat' => null, 'lng' => null],
        ['lat' => -16.52, 'lng' => -68.12],
        ['lat' => -16.53, 'lng' => -68.11],
    ];

    expect(RerouteGeometryCalculator::firstStopWithCoords($stops))->toBe(['lat' => -16.52, 'lng' => -68.12])
        ->and(RerouteGeometryCalculator::firstStopWithCoords([['lat' => null, 'lng' => null]]))->toBeNull();
});

it('el estimador OSRM parsea la geometría geojson a puntos {lat, lng}', function (): void {
    config(['logistics-telemetry.eta.osrm_host' => 'http://osrm.test']);
    Http::fake([
        'osrm.test/*' => Http::response([
            'code' => 'Ok',
            'routes' => [[
                'geometry' => ['coordinates' => [[-68.15, -16.50], [-68.14, -16.505], [-68.13, -16.51]]],
            ]],
        ]),
    ]);

    $estimator = new OsrmRoadGeometryEstimator;
    $geometry = $estimator->geometry(GeoPoint::of(-16.50, -68.15), GeoPoint::of(-16.51, -68.13));

    expect($geometry)->toBe([
        ['lat' => -16.50, 'lng' => -68.15],
        ['lat' => -16.505, 'lng' => -68.14],
        ['lat' => -16.51, 'lng' => -68.13],
    ]);
});

it('el estimador OSRM devuelve null sin host configurado o ante un fallo', function (): void {
    config(['logistics-telemetry.eta.osrm_host' => '']);
    $estimator = new OsrmRoadGeometryEstimator;

    expect($estimator->geometry(GeoPoint::of(-16.50, -68.15), GeoPoint::of(-16.51, -68.13)))->toBeNull();

    config(['logistics-telemetry.eta.osrm_host' => 'http://osrm.test']);
    Http::fake(['osrm.test/*' => Http::response('boom', 500)]);

    expect($estimator->geometry(GeoPoint::of(-16.50, -68.15), GeoPoint::of(-16.51, -68.13)))->toBeNull();
});
