<?php

declare(strict_types=1);

use Logistics\Execution\Domain\Event\DriverRouteClosed;
use Logistics\Execution\Domain\Event\DriverRouteStarted;
use Logistics\Execution\Domain\Event\DriverRouteStopArrived;
use Logistics\Execution\Domain\Exception\ExecutionException;
use Logistics\Execution\Domain\Model\RouteStop;
use Logistics\Execution\Domain\Model\VehicleRoute;
use Logistics\Execution\Domain\ValueObject\ArrivalSource;
use Logistics\Execution\Domain\ValueObject\GeoPoint;
use Logistics\Execution\Domain\ValueObject\IncidentSeverity;
use Logistics\Execution\Domain\ValueObject\RouteDate;
use Logistics\Execution\Domain\ValueObject\RouteId;
use Logistics\Execution\Domain\ValueObject\RouteStatus;
use Logistics\Execution\Domain\ValueObject\RouteStopId;
use Logistics\Execution\Domain\ValueObject\RouteStopStatus;

// Ciclo de vida de la ruta del día (inicio/cierre) y llegada a la parada, en el agregado (PHP puro).

function vrlRoute(): VehicleRoute
{
    $route = VehicleRoute::assemble(RouteId::generate(), 'VEH-1', RouteDate::fromString('2026-11-12'), [
        RouteStop::plan(RouteStopId::generate(), 'SHIP-1', 1),
        RouteStop::plan(RouteStopId::generate(), 'SHIP-2', 2),
    ]);
    $route->releaseEvents();

    return $route;
}

it('inicia una sola vez: en curso, con hora y odómetro', function (): void {
    $route = vrlRoute();
    $at = new DateTimeImmutable('2026-11-12T08:00:00-04:00');

    expect($route->start($at, 1000.0, GeoPoint::of(-16.5, -68.1)))->toBeTrue()
        ->and($route->status())->toBe(RouteStatus::InProgress)
        ->and($route->startedAt())->toEqual($at)
        ->and($route->startOdometerKm())->toBe(1000.0);

    $events = $route->releaseEvents();
    expect($events)->toHaveCount(1)->and($events[0])->toBeInstanceOf(DriverRouteStarted::class)
        ->and($events[0]->eventName())->toBe('execution.driver_route_started');

    // Reintento: no-op, sin evento.
    expect($route->start(new DateTimeImmutable('2026-11-12T09:00:00-04:00')))->toBeFalse()
        ->and($route->releaseEvents())->toBe([])
        ->and($route->startedAt())->toEqual($at);
});

it('no inicia una ruta suspendida por incidente mayor', function (): void {
    $route = vrlRoute();
    $route->reportIncident(IncidentSeverity::Major, 0, new DateTimeImmutable('2026-11-12T07:00:00-04:00'));

    $route->start(new DateTimeImmutable('2026-11-12T08:00:00-04:00'));
})->throws(ExecutionException::class);

it('cierra sin exigir paradas entregadas, informa pendientes y un POD tardío no la reabre', function (): void {
    $route = vrlRoute();
    $route->start(new DateTimeImmutable('2026-11-12T08:00:00-04:00'), 1000.0);
    $route->completeStop('SHIP-1', new DateTimeImmutable('2026-11-12T10:00:00-04:00'));
    $route->releaseEvents();

    expect($route->close(new DateTimeImmutable('2026-11-12T18:00:00-04:00'), 1080.0))->toBeTrue()
        ->and($route->status())->toBe(RouteStatus::Completed)
        ->and($route->stopsRemaining())->toBe(1);

    $events = $route->releaseEvents();
    expect($events[0])->toBeInstanceOf(DriverRouteClosed::class)
        ->and($events[0]->payload()['pending_stops'])->toBe(1)
        ->and($events[0]->payload()['completed_stops'])->toBe(1);

    // Idempotente.
    expect($route->close(new DateTimeImmutable('2026-11-12T19:00:00-04:00')))->toBeFalse();

    // POD tardío (cola offline) de la parada que quedó pendiente: cierra la parada, la ruta sigue cerrada.
    $route->completeStop('SHIP-2', new DateTimeImmutable('2026-11-12T18:30:00-04:00'));
    expect($route->status())->toBe(RouteStatus::Completed)->and($route->isClosed())->toBeTrue();
});

it('rechaza un odómetro de cierre menor que el de salida', function (): void {
    $route = vrlRoute();
    $route->start(new DateTimeImmutable('2026-11-12T08:00:00-04:00'), 1000.0);

    $route->close(new DateTimeImmutable('2026-11-12T18:00:00-04:00'), 900.0);
})->throws(ExecutionException::class);

it('registra la primera llegada a la parada y emite execution.stop_arrived una sola vez', function (): void {
    $route = vrlRoute();
    $first = new DateTimeImmutable('2026-11-12T10:00:00-04:00');

    expect($route->arriveAtStop('SHIP-1', $first, ArrivalSource::Geofence, GeoPoint::of(-16.5, -68.1)))->toBeTrue()
        ->and($route->arriveAtStop('SHIP-1', new DateTimeImmutable('2026-11-12T10:05:00-04:00'), ArrivalSource::Manual))->toBeFalse();

    $stop = $route->stops()[0];
    expect($stop->arrivedAt())->toEqual($first)
        ->and($stop->arrivalSource())->toBe(ArrivalSource::Geofence)
        ->and($stop->status())->toBe(RouteStopStatus::Pending);

    $events = $route->releaseEvents();
    expect($events)->toHaveCount(1)->and($events[0])->toBeInstanceOf(DriverRouteStopArrived::class)
        ->and($events[0]->eventName())->toBe('execution.stop_arrived')
        ->and($events[0]->payload()['source'])->toBe('geofence');
});

it('rechaza la llegada de un envío que no pertenece a la ruta', function (): void {
    vrlRoute()->arriveAtStop('SHIP-X', new DateTimeImmutable, ArrivalSource::Manual);
})->throws(ExecutionException::class);

it('GeoPoint::fromNullable descarta la isla nula y las coordenadas ausentes', function (): void {
    expect(GeoPoint::fromNullable(0.0, 0.0))->toBeNull()
        ->and(GeoPoint::fromNullable(null, -68.1))->toBeNull()
        ->and(GeoPoint::fromNullable(-16.5, -68.1, 8.0)?->accuracyM)->toBe(8.0);
});
