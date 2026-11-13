<?php

declare(strict_types=1);

use Logistics\FleetOps\Application\Command\ActivatePlan\ActivatePlan;
use Logistics\FleetOps\Application\Command\DeactivatePlan\DeactivatePlan;
use Logistics\FleetOps\Application\Command\RecordServiceDone\RecordServiceDone;
use Logistics\FleetOps\Domain\Exception\FleetOpsException;
use Logistics\FleetOps\Infrastructure\Persistence\Eloquent\Factory\MaintenancePlanFactory;
use Logistics\FleetOps\Infrastructure\Persistence\Eloquent\Model\MaintenancePlanRecord;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Comandos de plan de mantenimiento contra PostgreSQL (logistics_test): activar, desactivar y registrar el
 * servicio ejecutado (recalcula el próximo vencimiento). El instante lo pone el Clock de la aplicación.
 */
beforeEach(function (): void {})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

it('activa un plan inactivo', function (): void {
    $id = MaintenancePlanFactory::new()->inactive()->create()->id;

    app(CommandBus::class)->dispatch(new ActivatePlan($id));

    expect(MaintenancePlanRecord::query()->findOrFail($id)->active)->toBeTrue();
});

it('desactiva un plan activo', function (): void {
    $id = MaintenancePlanFactory::new()->create(['active' => true])->id;

    app(CommandBus::class)->dispatch(new DeactivatePlan($id));

    expect(MaintenancePlanRecord::query()->findOrFail($id)->active)->toBeFalse();
});

it('registra el servicio ejecutado y recalcula el próximo vencimiento por km', function (): void {
    $id = MaintenancePlanFactory::new()->create(['interval_km' => 10_000, 'interval_days' => null])->id;

    app(CommandBus::class)->dispatch(new RecordServiceDone($id, 100_000));

    $record = MaintenancePlanRecord::query()->findOrFail($id);
    expect((int) $record->last_done_km)->toBe(100_000)
        ->and((int) $record->next_due_km)->toBe(110_000);
});

it('falla al activar un plan inexistente', function (): void {
    expect(fn () => app(CommandBus::class)->dispatch(new ActivatePlan('019299ff-0000-7000-8000-00000000b000')))
        ->toThrow(FleetOpsException::class, 'No existe el plan');
});
