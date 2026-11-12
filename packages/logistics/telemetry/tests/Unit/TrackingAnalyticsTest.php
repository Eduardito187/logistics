<?php

declare(strict_types=1);

use Logistics\Telemetry\Domain\Model\VehiclePing;
use Logistics\Telemetry\Domain\Service\EtaEstimator;
use Logistics\Telemetry\Domain\Service\RouteDeviationMeter;
use Logistics\Telemetry\Domain\Service\SustainedCondition;
use Logistics\Telemetry\Domain\Service\TrackDownsampler;
use Logistics\Telemetry\Domain\ValueObject\FixKey;
use Logistics\Telemetry\Domain\ValueObject\GeoPoint;
use Logistics\Telemetry\Domain\ValueObject\PingId;
use Logistics\Telemetry\Domain\ValueObject\PingSource;

// Instante base de las trazas y salidas de los tests de ETA.
const TRACKING_ANALYTICS_T0 = '2026-11-12T10:00:00Z';

// Servicios de dominio puros del seguimiento: idempotencia por lectura, condiciones sostenidas, desvío de
// ruta, reducción de traza y ETA dinámica (respaldo en línea recta vs duraciones viales). PHP puro.

function analyticsPing(float $lat, float $lng, string $at): VehiclePing
{
    $instant = new DateTimeImmutable($at);

    return VehiclePing::record(
        PingId::generate(),
        'veh-1',
        null,
        null,
        null,
        GeoPoint::of($lat, $lng),
        null,
        null,
        null,
        null,
        null,
        $instant,
        $instant,
        PingSource::App,
    );
}

it('derives the same fix key for a retried reading and prefers the client fix id', function (): void {
    $at = new DateTimeImmutable('2026-11-12T10:00:00-04:00');

    expect(FixKey::of(null, 'dev-1', $at))->toBe(FixKey::of(null, 'dev-1', new DateTimeImmutable('2026-11-12T14:00:00Z')))
        ->and(FixKey::of(null, 'dev-1', $at))->not->toBe(FixKey::of(null, 'dev-2', $at))
        ->and(FixKey::of('fix-42', 'dev-1', $at))->toBe('id:fix-42')
        ->and(FixKey::of('  ', 'dev-1', $at))->toStartWith('dt:dev-1@');
});

it('fires a sustained condition only after the minimum seconds and resets when it stops holding', function (): void {
    $condition = new SustainedCondition;
    $t0 = new DateTimeImmutable(TRACKING_ANALYTICS_T0);

    $s1 = $condition->step(null, true, $t0, 20);
    $s2 = $condition->step($s1['since'], true, $t0->modify('+10 seconds'), 20);
    $s3 = $condition->step($s2['since'], true, $t0->modify('+25 seconds'), 20);
    $s4 = $condition->step($s3['since'], false, $t0->modify('+30 seconds'), 20);

    expect($s1['fire'])->toBeFalse()
        ->and($s2['fire'])->toBeFalse()
        ->and($s3['fire'])->toBeTrue()
        ->and($s3['sustained_seconds'])->toBe(25)
        ->and($s4['since'])->toBeNull()
        ->and($s4['fire'])->toBeFalse()
        ->and($condition->step(null, true, $t0, 0)['fire'])->toBeTrue();
});

it('measures the distance from a position to the planned route polyline', function (): void {
    $meter = new RouteDeviationMeter;
    $route = [GeoPoint::of(-16.50, -68.20), GeoPoint::of(-16.50, -68.10)];

    // Sobre la ruta: ~0 m. A 0.02° de latitud al sur (~2,2 km).
    expect($meter->distanceToRoute(GeoPoint::of(-16.50, -68.15), $route))->toBeLessThan(1.0)
        ->and($meter->distanceToRoute(GeoPoint::of(-16.52, -68.15), $route))->toBeGreaterThan(2000.0)
        ->and($meter->distanceToRoute(GeoPoint::of(-16.52, -68.15), $route))->toBeLessThan(2400.0)
        ->and($meter->distanceToRoute(GeoPoint::of(-16.50, -68.15), []))->toBeNull();
});

it('downsamples a track by spacing and caps the number of points keeping first and last', function (): void {
    $track = [];
    for ($i = 0; $i < 100; $i++) {
        // Un punto cada ~11 m hacia el este.
        $track[] = analyticsPing(-16.5, -68.15 + $i * 0.0001, sprintf('2026-11-12T10:%02d:%02dZ', intdiv($i, 60), $i % 60));
    }

    $sampler = new TrackDownsampler;
    $spaced = $sampler->downsample($track, 50.0, 500);
    $capped = $sampler->downsample($track, 0.0, 10);

    expect(count($spaced))->toBeLessThan(30)
        ->and($spaced[0])->toBe($track[0])
        ->and($spaced[count($spaced) - 1])->toBe($track[99])
        ->and(count($capped))->toBeLessThanOrEqual(10)
        ->and($capped[count($capped) - 1])->toBe($track[99]);
});

it('estimates chained stop ETAs by straight line when there are no road durations', function (): void {
    $estimator = new EtaEstimator;
    $departure = new DateTimeImmutable(TRACKING_ANALYTICS_T0);
    $stops = [
        ['shipment_id' => 's-1', 'sequence' => 1, 'point' => GeoPoint::of(-16.50, -68.14)],
        ['shipment_id' => 's-2', 'sequence' => 2, 'point' => null],
        ['shipment_id' => 's-3', 'sequence' => 3, 'point' => GeoPoint::of(-16.50, -68.12)],
    ];

    $etas = $estimator->estimate(GeoPoint::of(-16.50, -68.15), $departure, $stops, null, 30.0, 1.0, 5);

    expect($etas)->toHaveCount(3)
        ->and($etas[0]['method'])->toBe(EtaEstimator::METHOD_STRAIGHT_LINE)
        ->and($etas[0]['eta_at'])->toBeInstanceOf(DateTimeImmutable::class)
        ->and($etas[1]['eta_at'])->toBeNull()
        ->and($etas[2]['remaining_distance_m'])->toBeGreaterThan($etas[0]['remaining_distance_m'] ?? 0)
        // La segunda parada con coordenadas suma su tramo + 5 min de servicio de la anterior.
        ->and(($etas[2]['remaining_duration_s'] ?? 0) - ($etas[0]['remaining_duration_s'] ?? 0))->toBeGreaterThan(300);
});

it('uses road durations for the ETA when they match the located stops', function (): void {
    $estimator = new EtaEstimator;
    $departure = new DateTimeImmutable(TRACKING_ANALYTICS_T0);
    $stops = [
        ['shipment_id' => 's-1', 'sequence' => 1, 'point' => GeoPoint::of(-16.50, -68.14)],
        ['shipment_id' => 's-2', 'sequence' => 2, 'point' => GeoPoint::of(-16.50, -68.12)],
    ];
    $legs = [['distance_m' => 1500.0, 'duration_s' => 240.0], ['distance_m' => 2500.0, 'duration_s' => 360.0]];

    $etas = $estimator->estimate(GeoPoint::of(-16.50, -68.15), $departure, $stops, $legs, 30.0, 1.3, 5);

    expect($etas[0]['method'])->toBe(EtaEstimator::METHOD_OSRM)
        ->and($etas[0]['remaining_duration_s'])->toBe(240)
        ->and($etas[1]['remaining_duration_s'])->toBe(240 + 300 + 360)
        ->and($etas[1]['remaining_distance_m'])->toBe(4000)
        ->and($etas[1]['eta_at']?->format(DATE_ATOM))->toBe('2026-11-12T10:15:00+00:00');
});
