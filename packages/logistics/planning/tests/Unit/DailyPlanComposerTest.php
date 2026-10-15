<?php

declare(strict_types=1);

use Logistics\Planning\Domain\Service\DailyPlanComposer;
use Logistics\Planning\Domain\ValueObject\AutoPlanParameters;
use Logistics\Planning\Domain\ValueObject\DeliveryCandidate;
use Logistics\Planning\Domain\ValueObject\PlanStrategy;
use Logistics\Planning\Domain\ValueObject\TruckLoad;
use Logistics\Planning\Domain\ValueObject\VehicleCapacitySpec;

/**
 * Parámetros de prueba: sin holgura (se usa el 100 % de la capacidad nominal) para que las cuentas de
 * capacidad del test sean exactas.
 */
function planParams(PlanStrategy $strategy = PlanStrategy::Optimization): AutoPlanParameters
{
    return new AutoPlanParameters(
        $strategy,
        '2026-10-13T07:30:00+00:00',
        10.0,
        0.05,
        0.0,
    );
}

/**
 * Camión disponible con tripulación (puede salir a ruta).
 */
function vehicle(
    string $id,
    float $kg = 1000.0,
    float $m3 = 1000.0,
    ?string $departmentId = null,
    bool $hasCrew = true,
): VehicleCapacitySpec {
    return VehicleCapacitySpec::of($id, $kg, $m3, 'node-base', $hasCrew, $departmentId);
}

/**
 * Pedido candidato a entregar.
 */
function candidate(
    string $id,
    string $departmentId,
    float $kg,
    float $m3 = 0.1,
    ?string $slot = null,
    string $zone = 'z1',
): DeliveryCandidate {
    return DeliveryCandidate::of($id, -16.5, -68.15, $kg, $m3, $departmentId, $zone, $slot);
}

/**
 * Mapa camión → lista ordenada de envíos, para comparar composiciones de forma estable.
 *
 * @param  list<TruckLoad>             $trucks
 * @return array<string, list<string>>
 */
function truckShipmentMap(array $trucks): array
{
    $map = [];
    foreach ($trucks as $truck) {
        $map[$truck->vehicleId] = array_map(
            static fn (DeliveryCandidate $c): string => $c->shipmentId,
            $truck->candidates,
        );
    }
    ksort($map);

    return $map;
}

it('packs by capacity, leaving the overflow unassigned', function (): void {
    $composer = new DailyPlanComposer;
    // Un camión de 20 kg; tres pedidos de 8 kg: entran dos (16 kg), el tercero no (24 kg).
    $trucks = [vehicle('veh-1', kg: 20.0, m3: 100.0)];
    $candidates = [
        candidate('shp-a', 'LP', 8.0),
        candidate('shp-b', 'LP', 8.0),
        candidate('shp-c', 'LP', 8.0),
    ];

    $composition = $composer->compose($candidates, $trucks, planParams());

    expect($composition->trucksPlanned())->toBe(1)
        ->and($composition->stopsPlanned())->toBe(2)
        ->and($composition->unassignedCount())->toBe(1)
        ->and($composition->trucks[0]->usedKg)->toBe(16.0);
});

it('never mixes departments in one truck (one truck = one department)', function (): void {
    $composer = new DailyPlanComposer;
    // Un único camión flexible (sin departamento propio) y pedidos de dos departamentos.
    $trucks = [vehicle('veh-1')];
    $candidates = [
        candidate('shp-lp-1', 'LP', 5.0),
        candidate('shp-lp-2', 'LP', 5.0),
        candidate('shp-sc-1', 'SC', 5.0),
        candidate('shp-sc-2', 'SC', 5.0),
    ];

    $composition = $composer->compose($candidates, $trucks, planParams());

    // El camión queda ligado al primer departamento (LP, orden estable de clave) y toma solo sus pedidos.
    expect($composition->trucksPlanned())->toBe(1)
        ->and($composition->trucks[0]->departmentId)->toBe('LP')
        ->and($composition->trucks[0]->stopCount())->toBe(2)
        // Los pedidos de SC quedan sin asignar: no se mezclan en el mismo camión.
        ->and($composition->unassignedCount())->toBe(2);

    foreach ($composition->trucks[0]->candidates as $assigned) {
        expect($assigned->departmentId)->toBe('LP');
    }
});

it('serves each department on its own truck when there are enough trucks', function (): void {
    $composer = new DailyPlanComposer;
    $trucks = [vehicle('veh-1'), vehicle('veh-2')];
    $candidates = [
        candidate('shp-lp', 'LP', 5.0),
        candidate('shp-sc', 'SC', 5.0),
    ];

    $composition = $composer->compose($candidates, $trucks, planParams());

    expect($composition->trucksPlanned())->toBe(2)
        ->and($composition->unassignedCount())->toBe(0);

    // Cada camión sirve a un único departamento.
    $departments = array_map(static fn (TruckLoad $t): ?string => $t->departmentId, $composition->trucks);
    sort($departments);
    expect($departments)->toBe(['LP', 'SC']);
});

it('binds a truck to a single delivery slot in Slots strategy', function (): void {
    $composer = new DailyPlanComposer;
    $trucks = [vehicle('veh-1')];
    $candidates = [
        candidate('shp-am-1', 'LP', 5.0, slot: 'AM'),
        candidate('shp-am-2', 'LP', 5.0, slot: 'AM'),
        candidate('shp-pm-1', 'LP', 5.0, slot: 'PM'),
    ];

    $composition = $composer->compose($candidates, $trucks, planParams(PlanStrategy::Slots));

    // El camión queda ligado a la franja AM y no toma la PM (una franja por camión).
    expect($composition->trucksPlanned())->toBe(1)
        ->and($composition->trucks[0]->stopCount())->toBe(2)
        ->and($composition->unassignedCount())->toBe(1);
});

it('ignores vehicles without a full crew', function (): void {
    $composer = new DailyPlanComposer;
    $trucks = [vehicle('veh-1', hasCrew: false)];
    $candidates = [candidate('shp-a', 'LP', 5.0)];

    $composition = $composer->compose($candidates, $trucks, planParams());

    expect($composition->trucksPlanned())->toBe(0)
        ->and($composition->unassignedCount())->toBe(1);
});

it('is idempotent: re-composing the same input yields the same plan', function (): void {
    $composer = new DailyPlanComposer;
    $trucks = [vehicle('veh-1', kg: 20.0), vehicle('veh-2', kg: 20.0)];
    $candidates = [
        candidate('shp-a', 'LP', 8.0),
        candidate('shp-b', 'LP', 8.0),
        candidate('shp-c', 'LP', 8.0),
        candidate('shp-d', 'SC', 8.0),
    ];

    $first = $composer->compose($candidates, $trucks, planParams());
    $second = $composer->compose($candidates, $trucks, planParams());

    expect(truckShipmentMap($second->trucks))->toBe(truckShipmentMap($first->trucks))
        ->and($second->unassignedCount())->toBe($first->unassignedCount())
        ->and($second->trucksPlanned())->toBe($first->trucksPlanned());
});
