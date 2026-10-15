<?php

declare(strict_types=1);

use Logistics\Execution\Domain\Model\RouteStop;
use Logistics\Execution\Domain\Model\VehicleRoute;
use Logistics\Execution\Domain\ValueObject\IncidentSeverity;
use Logistics\Execution\Domain\ValueObject\RouteDate;
use Logistics\Execution\Domain\ValueObject\RouteId;
use Logistics\Execution\Domain\ValueObject\RouteStatus;
use Logistics\Execution\Domain\ValueObject\RouteStopId;
use Logistics\Execution\Domain\ValueObject\RouteStopStatus;

/**
 * Arma una ruta de cuatro paradas (secuencia 1..4) con una ETA inicial por parada, a partir de la
 * salida 07:00. Las paradas 1..4 llegan a los 20, 40, 60 y 80 minutos.
 */
function routeOfFour(): VehicleRoute
{
    $depart = new DateTimeImmutable('2026-10-13T07:00:00+00:00');
    $stops = [];
    foreach (['shp-1', 'shp-2', 'shp-3', 'shp-4'] as $i => $shipmentId) {
        $stops[] = RouteStop::plan(
            RouteStopId::generate(),
            $shipmentId,
            $i + 1,
            null,
            null,
            null,
            $depart->modify('+'.(($i + 1) * 20).' minutes'),
        );
    }

    return VehicleRoute::assemble(RouteId::generate(), 'veh-1', RouteDate::fromString('2026-10-13'), $stops);
}

/**
 * Devuelve la parada del envío dado.
 */
function stopFor(VehicleRoute $route, string $shipmentId): RouteStop
{
    foreach ($route->stops() as $stop) {
        if ($stop->shipmentId() === $shipmentId) {
            return $stop;
        }
    }

    throw new RuntimeException("sin parada para {$shipmentId}");
}

it('MAJOR incident suspends the route and reschedules only the pending stops', function (): void {
    $route = routeOfFour();
    // La primera parada ya se entregó: no debe tocarse por el incidente.
    $route->completeStop('shp-1', new DateTimeImmutable('2026-10-13T07:25:00+00:00'));
    $route->releaseEvents();

    $affected = $route->reportIncident(IncidentSeverity::Major, 0, new DateTimeImmutable('2026-10-13T08:00:00+00:00'));

    // Devuelve, en orden, los envíos de las paradas que seguían pendientes.
    expect($affected)->toBe(['shp-2', 'shp-3', 'shp-4']);

    // El camión queda fuera de servicio (ruta suspendida).
    expect($route->status())->toBe(RouteStatus::Suspended);

    // La parada entregada no cambia; las pendientes quedan reprogramadas (salen de esta ruta).
    expect(stopFor($route, 'shp-1')->status())->toBe(RouteStopStatus::Completed)
        ->and(stopFor($route, 'shp-2')->status())->toBe(RouteStopStatus::Rescheduled)
        ->and(stopFor($route, 'shp-3')->status())->toBe(RouteStopStatus::Rescheduled)
        ->and(stopFor($route, 'shp-4')->status())->toBe(RouteStopStatus::Rescheduled);

    // Ya no quedan paradas pendientes en esta jornada.
    expect($route->remainingStops())->toBe([]);
});

it('MAJOR incident does not record its own domain event (the VehicleIncident aggregate does)', function (): void {
    $route = routeOfFour();
    $route->releaseEvents();

    $route->reportIncident(IncidentSeverity::Major, 0, new DateTimeImmutable('2026-10-13T08:00:00+00:00'));

    expect($route->releaseEvents())->toBe([]);
});

it('MINOR incident keeps the route going and pushes the ETAs of pending stops', function (): void {
    $route = routeOfFour();

    $affected = $route->reportIncident(IncidentSeverity::Minor, 30, new DateTimeImmutable('2026-10-13T07:30:00+00:00'));

    expect($affected)->toBe(['shp-1', 'shp-2', 'shp-3', 'shp-4']);

    // La ruta NO cambia de estado: sigue, con retraso.
    expect($route->status())->toBe(RouteStatus::Planned);

    // Las paradas siguen pendientes y su ETA se empujó 30 minutos (20 -> 50, 40 -> 70, ...).
    expect(stopFor($route, 'shp-1')->status())->toBe(RouteStopStatus::Pending)
        ->and(stopFor($route, 'shp-1')->etaAt()?->format(DATE_ATOM))->toBe('2026-10-13T07:50:00+00:00')
        ->and(stopFor($route, 'shp-2')->etaAt()?->format(DATE_ATOM))->toBe('2026-10-13T08:10:00+00:00');

    expect($route->remainingStops())->toHaveCount(4);
});

it('MINOR incident leaves already-settled stops untouched', function (): void {
    $route = routeOfFour();
    $route->completeStop('shp-1', new DateTimeImmutable('2026-10-13T07:25:00+00:00'));

    $affected = $route->reportIncident(IncidentSeverity::Minor, 30, new DateTimeImmutable('2026-10-13T07:30:00+00:00'));

    // La parada entregada no entra en los afectados ni cambia su ETA.
    expect($affected)->toBe(['shp-2', 'shp-3', 'shp-4'])
        ->and(stopFor($route, 'shp-1')->etaAt()?->format(DATE_ATOM))->toBe('2026-10-13T07:20:00+00:00')
        ->and(stopFor($route, 'shp-1')->status())->toBe(RouteStopStatus::Completed);
});
