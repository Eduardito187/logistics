<?php

declare(strict_types=1);

use Logistics\Planning\Domain\Service\DailyPlanComposer;
use Logistics\Planning\Domain\ValueObject\AutoPlanParameters;
use Logistics\Planning\Domain\ValueObject\DeliveryCandidate;
use Logistics\Planning\Domain\ValueObject\PlanStrategy;
use Logistics\Planning\Domain\ValueObject\ShipmentRouteRequirement;
use Logistics\Planning\Domain\ValueObject\VehicleCapacitySpec;
use Logistics\Planning\Domain\ValueObject\VehicleRouteProfile;

/**
 * El empaque del auto-planificador (DailyPlanComposer) aplica las restricciones DURAS de ruteo (R-04):
 * descarta —deja SIN ASIGNAR— los envíos que el camión no puede servir (peso/altura/zona/tipo de carga),
 * sin romper la invariante de capacidad ni la de un camión = un departamento.
 *
 * Helpers con prefijo del package para no chocar con los de otros archivos de test (Pest carga todos en un
 * proceso).
 */
function restrictionsParams(): AutoPlanParameters
{
    return new AutoPlanParameters(PlanStrategy::Optimization, '2026-10-13T07:30:00+00:00', 10.0, 0.05, 0.0);
}

function restrictedVehicle(string $id, VehicleRouteProfile $profile): VehicleCapacitySpec
{
    return VehicleCapacitySpec::of($id, 1000.0, 1000.0, 'node-base', true, 'LP', null, null, $profile);
}

function requiringCandidate(string $id, ShipmentRouteRequirement $requirement): DeliveryCandidate
{
    return DeliveryCandidate::of(
        shipmentId: $id,
        latitude: -16.5,
        longitude: -68.15,
        weightKg: 5.0,
        volumeM3: 0.1,
        departmentId: 'LP',
        zoneKey: 'z1',
        requirement: $requirement,
    );
}

it('leaves unassigned a shipment too heavy for the only vehicle (R-04 weight)', function (): void {
    $composer = new DailyPlanComposer;
    $trucks = [restrictedVehicle('veh-1', VehicleRouteProfile::of(maxWeightKg: 20.0))];
    $candidates = [
        requiringCandidate('shp-ok', ShipmentRouteRequirement::of(weightKg: 15.0)),
        requiringCandidate('shp-heavy', ShipmentRouteRequirement::of(weightKg: 80.0)),
    ];

    $composition = $composer->compose($candidates, $trucks, restrictionsParams());

    // Solo el envío factible entra; el que excede el peso por bulto queda sin asignar.
    expect($composition->stopsPlanned())->toBe(1)
        ->and($composition->unassignedCount())->toBe(1)
        ->and($composition->unassigned[0]->shipmentId)->toBe('shp-heavy');
});

it('leaves unassigned a shipment bound to a forbidden zone (R-04 zone)', function (): void {
    $composer = new DailyPlanComposer;
    $trucks = [restrictedVehicle('veh-1', VehicleRouteProfile::of(forbiddenZones: ['centro']))];
    $candidates = [
        requiringCandidate('shp-sur', ShipmentRouteRequirement::of(zone: 'sur')),
        requiringCandidate('shp-centro', ShipmentRouteRequirement::of(zone: 'centro')),
    ];

    $composition = $composer->compose($candidates, $trucks, restrictionsParams());

    expect($composition->stopsPlanned())->toBe(1)
        ->and($composition->unassignedCount())->toBe(1)
        ->and($composition->unassigned[0]->shipmentId)->toBe('shp-centro');
});

it('leaves unassigned a shipment too tall for the only vehicle (R-04 height)', function (): void {
    $composer = new DailyPlanComposer;
    $trucks = [restrictedVehicle('veh-1', VehicleRouteProfile::of(maxHeightM: 2.0))];
    $candidates = [
        requiringCandidate('shp-low', ShipmentRouteRequirement::of(heightM: 1.5)),
        requiringCandidate('shp-tall', ShipmentRouteRequirement::of(heightM: 3.2)),
    ];

    $composition = $composer->compose($candidates, $trucks, restrictionsParams());

    expect($composition->stopsPlanned())->toBe(1)
        ->and($composition->unassignedCount())->toBe(1)
        ->and($composition->unassigned[0]->shipmentId)->toBe('shp-tall');
});

it('keeps the previous behaviour when neither vehicle nor shipments declare restrictions', function (): void {
    $composer = new DailyPlanComposer;
    $trucks = [restrictedVehicle('veh-1', VehicleRouteProfile::unrestricted())];
    $candidates = [
        requiringCandidate('shp-a', ShipmentRouteRequirement::none()),
        requiringCandidate('shp-b', ShipmentRouteRequirement::none()),
    ];

    $composition = $composer->compose($candidates, $trucks, restrictionsParams());

    // Sin restricciones, todo se asigna: compatibilidad con el comportamiento previo.
    expect($composition->stopsPlanned())->toBe(2)
        ->and($composition->unassignedCount())->toBe(0);
});
