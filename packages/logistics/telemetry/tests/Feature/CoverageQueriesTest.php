<?php

declare(strict_types=1);

use Logistics\Shared\Application\Query\QueryBus;
use Logistics\Telemetry\Application\Query\GetGeofence\GetGeofence;
use Logistics\Telemetry\Application\Query\GetLiveFleetPositions\GetLiveFleetPositions;
use Logistics\Telemetry\Application\Query\GetStreetTraffic\GetStreetTraffic;
use Logistics\Telemetry\Application\Query\GetVehicleTelemetrySummary\GetVehicleTelemetrySummary;
use Logistics\Telemetry\Application\Query\GetVehicleTrack\GetVehicleTrack;
use Logistics\Telemetry\Application\Query\ListAlerts\ListAlerts;
use Logistics\Telemetry\Application\Query\ListGeofences\ListGeofences;
use Logistics\Telemetry\Application\Query\ListTrips\ListTrips;
use Logistics\Telemetry\Application\ReadModel\AlertView;
use Logistics\Telemetry\Application\ReadModel\GeofenceView;
use Logistics\Telemetry\Application\ReadModel\PingView;
use Logistics\Telemetry\Application\ReadModel\StreetTrafficView;
use Logistics\Telemetry\Application\ReadModel\TripView;
use Logistics\Telemetry\Application\ReadModel\VehicleLiveView;
use Logistics\Telemetry\Application\ReadModel\VehicleSummaryView;
use Logistics\Telemetry\Domain\ValueObject\AlertSeverity;
use Logistics\Telemetry\Domain\ValueObject\AlertType;
use Logistics\Telemetry\Domain\ValueObject\GeofenceType;
use Logistics\Telemetry\Infrastructure\Persistence\Eloquent\Factory\AlertFactory;
use Logistics\Telemetry\Infrastructure\Persistence\Eloquent\Factory\GeofenceFactory;
use Logistics\Telemetry\Infrastructure\Persistence\Eloquent\Factory\PingFactory;
use Logistics\Telemetry\Infrastructure\Persistence\Eloquent\Factory\StreetStatsFactory;
use Logistics\Telemetry\Infrastructure\Persistence\Eloquent\Factory\TripFactory;
use Logistics\Telemetry\Infrastructure\Persistence\Eloquent\Factory\VehiclePositionFactory;

/**
 * Cobertura del lado de lectura CQRS de telemetría: cada Query se resuelve por el QueryBus y proyecta sus
 * ReadModels a partir de filas reales sembradas por las fábricas del contexto. Patrón C del brief de
 * cobertura. Afirma la forma del resultado (tipos de ReadModel), no reglas de negocio.
 */
function telemetryQueryBus(): QueryBus
{
    /** @var QueryBus $bus */
    $bus = app(QueryBus::class);

    return $bus;
}

it('lista geocercas y filtra por tipo', function (): void {
    GeofenceFactory::new()->ofType(GeofenceType::ForbiddenZone)->create();
    GeofenceFactory::new()->ofType(GeofenceType::RestArea)->create();

    /** @var list<GeofenceView> $all */
    $all = telemetryQueryBus()->ask(new ListGeofences);
    /** @var list<GeofenceView> $forbidden */
    $forbidden = telemetryQueryBus()->ask(new ListGeofences(type: GeofenceType::ForbiddenZone->value));

    expect($all)->not->toBeEmpty()
        ->and($all[0])->toBeInstanceOf(GeofenceView::class)
        ->and($forbidden)->not->toBeEmpty();
});

it('obtiene una geocerca por id y devuelve null si no existe', function (): void {
    $geofence = GeofenceFactory::new()->create();

    /** @var GeofenceView|null $found */
    $found = telemetryQueryBus()->ask(new GetGeofence((string) $geofence->getKey()));
    /** @var GeofenceView|null $missing */
    $missing = telemetryQueryBus()->ask(new GetGeofence('0192ffff-ffff-7fff-8fff-ffffffffffff'));

    expect($found)->toBeInstanceOf(GeofenceView::class)
        ->and($missing)->toBeNull();
});

it('lista trips y proyecta TripView', function (): void {
    $vehicleId = '0192aaaa-bbbb-7ccc-8ddd-000000000001';
    TripFactory::new()->state(['vehicle_id' => $vehicleId])->create();

    /** @var list<TripView> $trips */
    $trips = telemetryQueryBus()->ask(new ListTrips(vehicleId: $vehicleId));

    expect($trips)->not->toBeEmpty()
        ->and($trips[0])->toBeInstanceOf(TripView::class);
});

it('resume la telemetría de un vehículo en un día', function (): void {
    $vehicleId = '0192aaaa-bbbb-7ccc-8ddd-000000000002';
    TripFactory::new()->state([
        'vehicle_id' => $vehicleId,
        'started_at' => new DateTimeImmutable('2026-10-05 08:00:00'),
        'ended_at' => new DateTimeImmutable('2026-10-05 09:00:00'),
    ])->create();

    /** @var VehicleSummaryView $summary */
    $summary = telemetryQueryBus()->ask(new GetVehicleTelemetrySummary($vehicleId, '2026-10-05'));

    expect($summary)->toBeInstanceOf(VehicleSummaryView::class)
        ->and($summary->date)->toBe('2026-10-05');
});

it('lista alertas y filtra por estado de reconocimiento', function (): void {
    AlertFactory::new()->ofType(AlertType::Speeding, AlertSeverity::Critical)->create();
    AlertFactory::new()->acknowledged()->create();

    /** @var list<AlertView> $unacknowledged */
    $unacknowledged = telemetryQueryBus()->ask(new ListAlerts(acknowledged: false));

    expect($unacknowledged)->not->toBeEmpty()
        ->and($unacknowledged[0])->toBeInstanceOf(AlertView::class);
});

it('devuelve las posiciones en vivo de la flota', function (): void {
    VehiclePositionFactory::new()->create();

    /** @var list<VehicleLiveView> $positions */
    $positions = telemetryQueryBus()->ask(new GetLiveFleetPositions);

    expect($positions)->not->toBeEmpty()
        ->and($positions[0])->toBeInstanceOf(VehicleLiveView::class)
        ->and($positions[0]->toArray())->toHaveKey('vehicle_id');
});

it('devuelve el recorrido de un vehículo entre dos instantes', function (): void {
    $vehicleId = '0192aaaa-bbbb-7ccc-8ddd-000000000003';
    PingFactory::new()->forVehicleAt($vehicleId, new DateTimeImmutable('2026-10-05 08:30:00'))->create();

    /** @var list<PingView> $track */
    $track = telemetryQueryBus()->ask(new GetVehicleTrack(
        $vehicleId,
        '2026-10-05T00:00:00-04:00',
        '2026-10-06T00:00:00-04:00',
    ));

    expect($track)->not->toBeEmpty()
        ->and($track[0])->toBeInstanceOf(PingView::class);
});

it('devuelve el tráfico agregado de calles filtrando el ruido por muestras mínimas', function (): void {
    StreetStatsFactory::new()->congested()->create();
    StreetStatsFactory::new()->create();

    /** @var list<StreetTrafficView> $segments */
    $segments = telemetryQueryBus()->ask(new GetStreetTraffic);

    expect($segments)->not->toBeEmpty()
        ->and($segments[0])->toBeInstanceOf(StreetTrafficView::class);
});
