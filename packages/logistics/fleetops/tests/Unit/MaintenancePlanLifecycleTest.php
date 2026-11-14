<?php

declare(strict_types=1);

use Logistics\FleetOps\Domain\Event\MaintenancePlanActivated;
use Logistics\FleetOps\Domain\Exception\FleetOpsException;
use Logistics\FleetOps\Domain\Model\MaintenancePlan;
use Logistics\FleetOps\Domain\ValueObject\MaintenancePlanId;
use Logistics\FleetOps\Domain\ValueObject\ServiceKind;

/**
 * Cubre los caminos del plan que no ejercita el test base: vehículo vacío al definir y la reactivación de
 * un plan inactivo (que graba MaintenancePlanActivated).
 */
it('rechaza definir un plan sin vehículo', function (): void {
    expect(fn () => MaintenancePlan::define(
        MaintenancePlanId::generate(),
        '   ',
        ServiceKind::OilChange,
        10_000,
        null,
    ))->toThrow(FleetOpsException::class, 'requiere un vehículo');
});

it('reactiva un plan inactivo y graba el evento de activación', function (): void {
    $plan = MaintenancePlan::define(
        MaintenancePlanId::generate(),
        'vehicle-1',
        ServiceKind::OilChange,
        10_000,
        180,
    );
    $plan->deactivate();
    $plan->releaseEvents();

    $plan->activate();

    expect($plan->isActive())->toBeTrue();
    $events = $plan->releaseEvents();
    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(MaintenancePlanActivated::class);
});
