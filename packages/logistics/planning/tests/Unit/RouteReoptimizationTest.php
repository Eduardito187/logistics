<?php

declare(strict_types=1);

use Logistics\Planning\Domain\Event\RouteReoptimized;
use Logistics\Planning\Domain\Exception\PlanningException;
use Logistics\Planning\Domain\Model\DeliveryPlan;
use Logistics\Planning\Domain\Service\RouteSequencer;
use Logistics\Planning\Domain\ValueObject\DistanceMatrix;
use Logistics\Planning\Domain\ValueObject\Eta;
use Logistics\Planning\Domain\ValueObject\PlanDate;
use Logistics\Planning\Domain\ValueObject\PlanId;
use Logistics\Planning\Domain\ValueObject\PlannedStopId;
use Logistics\Planning\Domain\ValueObject\RouteGeometry;

/**
 * Re-optimización incremental (R-05): re-secuenciar SOLO la ruta afectada reutilizando el RouteSequencer
 * (2-opt/Or-opt), sin rehacer el día. El núcleo es puro y determinista; estos tests cubren su propiedad
 * clave (mejora-o-mantiene e idempotencia) y el efecto sobre el agregado DeliveryPlan.
 *
 * Matriz 1D de prueba: depósito en x=0 y paradas 1..4 en x={10,20,30,40}; la distancia/tiempo es la
 * diferencia absoluta. El mejor camino abierto desde el depósito es el orden ascendente [1,2,3,4].
 */
function reoptLineMatrix(): DistanceMatrix
{
    $x = [0.0, 10.0, 20.0, 30.0, 40.0];
    $grid = [];
    foreach ($x as $from) {
        $row = [];
        foreach ($x as $to) {
            $row[] = abs($from - $to);
        }
        $grid[] = $row;
    }

    return DistanceMatrix::fromArrays($grid, $grid);
}

it('improves a suboptimal order and is idempotent on its own output (R-05)', function (): void {
    $sequencer = new RouteSequencer;
    $matrix = reoptLineMatrix();

    // Orden actual malo: cruza de ida y vuelta.
    $improved = $sequencer->improve($matrix, [3, 1, 4, 2]);

    // Re-secuenciar mejora al óptimo del camino abierto (ascendente desde el depósito).
    expect($improved)->toBe([1, 2, 3, 4]);

    // Idempotente: re-aplicar sobre su propia salida no cambia nada (punto fijo del descenso).
    expect($sequencer->improve($matrix, $improved))->toBe($improved);
});

it('never worsens an already optimal order (improve = mejora o mantiene)', function (): void {
    $sequencer = new RouteSequencer;
    $matrix = reoptLineMatrix();

    expect($sequencer->improve($matrix, [1, 2, 3, 4]))->toBe([1, 2, 3, 4]);
});

it('applies the new order and ETAs to the plan, invalidates geometry and records the event', function (): void {
    $plan = DeliveryPlan::startDraft(PlanId::generate(), PlanDate::fromString('2026-10-13'), 'veh-1');
    $plan->assignShipment(PlannedStopId::generate(), 'shp-1');
    $plan->assignShipment(PlannedStopId::generate(), 'shp-2');
    $plan->assignShipment(PlannedStopId::generate(), 'shp-3');

    // Una geometría previa que la re-optimización debe invalidar (cambió el orden).
    $plan->attachRouteGeometry(RouteGeometry::of([[0.0, 0.0], [1.0, 1.0]], 100.0, 60.0));

    $departAt = new DateTimeImmutable('2026-10-13T07:30:00+00:00');
    $etas = [
        'shp-3' => Eta::at($departAt->modify('+10 minutes'), 10),
        'shp-1' => Eta::at($departAt->modify('+20 minutes'), 20),
        'shp-2' => Eta::at($departAt->modify('+30 minutes'), 30),
    ];

    $plan->reoptimizeTo(['shp-3', 'shp-1', 'shp-2'], $etas, 'incident');

    $orderedIds = array_map(static fn ($stop): string => $stop->shipmentId(), $plan->stops());
    expect($orderedIds)->toBe(['shp-3', 'shp-1', 'shp-2'])
        ->and($plan->geometry())->toBeNull()
        ->and($plan->stops()[0]->eta()?->minutesFromDeparture)->toBe(10);

    $events = $plan->releaseEvents();
    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(RouteReoptimized::class);
});

it('rejects a reoptimization whose order is not exactly the plan shipments', function (): void {
    $plan = DeliveryPlan::startDraft(PlanId::generate(), PlanDate::fromString('2026-10-13'), 'veh-1');
    $plan->assignShipment(PlannedStopId::generate(), 'shp-1');
    $plan->assignShipment(PlannedStopId::generate(), 'shp-2');

    $departAt = new DateTimeImmutable('2026-10-13T07:30:00+00:00');
    $etas = ['shp-1' => Eta::at($departAt, 0), 'shp-2' => Eta::at($departAt, 0)];

    // Falta shp-2 en el orden: debe fallar (no se puede re-optimizar un subconjunto).
    expect(fn () => $plan->reoptimizeTo(['shp-1'], $etas, 'manual'))
        ->toThrow(PlanningException::class);
});
