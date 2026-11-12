<?php

declare(strict_types=1);

use Logistics\Planning\Domain\Exception\PlanningException;
use Logistics\Planning\Domain\Service\ShiftWindowRouteGuard;
use Logistics\Planning\Domain\ValueObject\CrewShiftWindow;
use Logistics\Planning\Domain\ValueObject\DeliveryCandidate;
use Logistics\Planning\Domain\ValueObject\Eta;
use Logistics\Planning\Domain\ValueObject\PlannedRoute;

// Ventana de turno de la tripulación (CrewShiftWindow) y política pura que recorta una ruta al turno
// (ShiftWindowRouteGuard). PHP puro: sin Laravel ni base.

/**
 * Ruta de prueba con una parada por cada ETA (minutos desde la salida) dada, en ese orden.
 *
 * @param list<int> $etaMinutes
 */
function shiftGuardRoute(DateTimeImmutable $departAt, array $etaMinutes): PlannedRoute
{
    $candidates = [];
    $etas = [];
    foreach ($etaMinutes as $index => $minutes) {
        $id = 'shp-'.($index + 1);
        $candidates[] = DeliveryCandidate::of($id, -16.5, -68.15, 10.0, 0.1, 'LP');
        $etas[$id] = Eta::at($departAt->modify("+{$minutes} minutes"), $minutes);
    }

    return new PlannedRoute('veh-1', 'LP', $candidates, $etas, null);
}

it('corre la salida al inicio del turno cuando se pide antes, y no la adelanta nunca', function (): void {
    $window = CrewShiftWindow::of(8 * 60, 18 * 60, 600);

    $early = $window->departureFor(new DateTimeImmutable('2026-10-20 06:15:00'));
    $late = $window->departureFor(new DateTimeImmutable('2026-10-20 09:40:00'));

    expect($early->format('Y-m-d H:i'))->toBe('2026-10-20 08:00')
        ->and($late->format('Y-m-d H:i'))->toBe('2026-10-20 09:40');
});

it('el límite de ruta es el menor entre lo que queda de ventana y la jornada máxima', function (): void {
    $window = CrewShiftWindow::of(5 * 60, 22 * 60, 600);

    // Salida 07:30: quedan 870 min de ventana, pero la jornada máxima es 600.
    expect($window->routeLimitMinutes(new DateTimeImmutable('2026-10-20 07:30:00')))->toBe(600)
        // Salida 18:00: quedan 240 min de ventana (< 600).
        ->and($window->routeLimitMinutes(new DateTimeImmutable('2026-10-20 18:00:00')))->toBe(240)
        // Salida pasada la ventana: 0.
        ->and($window->routeLimitMinutes(new DateTimeImmutable('2026-10-20 23:00:00')))->toBe(0);
});

it('rechaza una ventana de turno inválida', function (): void {
    CrewShiftWindow::of(600, 600, 480);
})->throws(PlanningException::class);

it('quita de la ruta las paradas que exceden el turno y todas las siguientes', function (): void {
    $departAt = new DateTimeImmutable('2026-10-20 08:00:00');
    $window = CrewShiftWindow::of(8 * 60, 12 * 60, 600); // límite 240 min desde las 08:00

    $result = (new ShiftWindowRouteGuard)->fit(shiftGuardRoute($departAt, [30, 200, 250, 100]), $departAt, $window);

    expect($result['route'])->not->toBeNull()
        ->and($result['route']?->orderedShipmentIds())->toBe(['shp-1', 'shp-2'])
        ->and(array_keys($result['route']->etas ?? []))->toBe(['shp-1', 'shp-2'])
        // Recortada: la geometría queda desactualizada y se descarta.
        ->and($result['route']?->geometry)->toBeNull()
        ->and(array_map(static fn (DeliveryCandidate $c): string => $c->shipmentId, $result['removed']))
        ->toBe(['shp-3', 'shp-4']);
});

it('deja la ruta intacta si entra en el turno y la descarta si ninguna parada entra', function (): void {
    $departAt = new DateTimeImmutable('2026-10-20 08:00:00');
    $guard = new ShiftWindowRouteGuard;

    $route = shiftGuardRoute($departAt, [10, 20]);
    $fits = $guard->fit($route, $departAt, CrewShiftWindow::of(8 * 60, 18 * 60, 600));
    expect($fits['route'])->toBe($route)
        ->and($fits['removed'])->toBe([]);

    $none = $guard->fit(shiftGuardRoute($departAt, [700, 710]), $departAt, CrewShiftWindow::of(8 * 60, 22 * 60, 600));
    expect($none['route'])->toBeNull()
        ->and($none['removed'])->toHaveCount(2);
});
