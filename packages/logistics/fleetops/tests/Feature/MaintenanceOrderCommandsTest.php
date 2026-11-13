<?php

declare(strict_types=1);

use Logistics\FleetOps\Application\Command\CancelMaintenance\CancelMaintenance;
use Logistics\FleetOps\Application\Command\StartMaintenance\StartMaintenance;
use Logistics\FleetOps\Application\Query\GetMaintenanceOrder\GetMaintenanceOrder;
use Logistics\FleetOps\Application\ReadModel\MaintenanceOrderView;
use Logistics\FleetOps\Domain\Exception\FleetOpsException;
use Logistics\FleetOps\Domain\ValueObject\MaintenanceStatus;
use Logistics\FleetOps\Infrastructure\Persistence\Eloquent\Factory\MaintenanceOrderFactory;
use Logistics\FleetOps\Infrastructure\Persistence\Eloquent\Model\MaintenanceOrderRecord;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Comandos de orden de mantenimiento contra PostgreSQL (logistics_test): iniciar y cancelar una orden
 * agendada, y la consulta puntual por id. El instante lo pone el Clock de la aplicación.
 */
beforeEach(function (): void {})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

it('inicia una orden agendada', function (): void {
    $id = MaintenanceOrderFactory::new()->withStatus(MaintenanceStatus::Scheduled)->create()->id;

    app(CommandBus::class)->dispatch(new StartMaintenance($id));

    expect(MaintenanceOrderRecord::query()->findOrFail($id)->status)->toBe(MaintenanceStatus::InProgress->value);
});

it('cancela una orden agendada', function (): void {
    $id = MaintenanceOrderFactory::new()->withStatus(MaintenanceStatus::Scheduled)->create()->id;

    app(CommandBus::class)->dispatch(new CancelMaintenance($id));

    expect(MaintenanceOrderRecord::query()->findOrFail($id)->status)->toBe(MaintenanceStatus::Cancelled->value);
});

it('consulta una orden por id y proyecta la vista', function (): void {
    $vehicleId = '019299ee-0000-7000-8000-00000000c001';
    $id = MaintenanceOrderFactory::new()->forVehicle($vehicleId)->withStatus(MaintenanceStatus::Scheduled)->create()->id;

    $view = app(QueryBus::class)->ask(new GetMaintenanceOrder($id));

    expect($view)->toBeInstanceOf(MaintenanceOrderView::class)
        ->and($view->id)->toBe($id)
        ->and($view->vehicleId)->toBe($vehicleId)
        ->and($view->status)->toBe(MaintenanceStatus::Scheduled->value);
});

it('devuelve null al consultar una orden inexistente', function (): void {
    $view = app(QueryBus::class)->ask(new GetMaintenanceOrder('019299ee-0000-7000-8000-00000000c999'));

    expect($view)->toBeNull();
});

it('falla al iniciar una orden inexistente', function (): void {
    expect(fn () => app(CommandBus::class)->dispatch(new StartMaintenance('019299ee-0000-7000-8000-00000000c000')))
        ->toThrow(FleetOpsException::class, 'No existe la orden');
});
