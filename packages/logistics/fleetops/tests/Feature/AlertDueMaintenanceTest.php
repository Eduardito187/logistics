<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Logistics\FleetOps\Application\Command\AlertDueMaintenance\AlertDueMaintenance;
use Logistics\FleetOps\Infrastructure\Persistence\Eloquent\Factory\MaintenancePlanFactory;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Barrido de mantenimiento preventivo contra PostgreSQL (logistics_test): si hay planes vencidos/por vencer
 * se emite un único evento de resumen al outbox (fleetops.maintenance_due); si no hay, no se emite nada.
 */
beforeEach(function (): void {})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

it('emite la alerta de resumen cuando hay un plan vencido por fecha', function (): void {
    // Plan activo con próximo servicio ya vencido respecto a la fecha de referencia.
    MaintenancePlanFactory::new()->forVehicle('01929a00-0000-7000-8000-00000000a001')->create([
        'active' => true,
        'next_due_at' => '2026-01-01 00:00:00',
    ]);

    app(CommandBus::class)->dispatch(new AlertDueMaintenance('2026-10-05'));

    expect(DB::table('outbox_messages')->where('aggregate_type', 'fleetops.maintenance_due')->count())->toBe(1);
});

it('no emite nada cuando no hay planes por vencer', function (): void {
    // Único plan desactivado: el barrido no lo considera.
    MaintenancePlanFactory::new()->inactive()->create(['next_due_at' => '2026-01-01 00:00:00']);

    app(CommandBus::class)->dispatch(new AlertDueMaintenance('2026-10-05'));

    expect(DB::table('outbox_messages')->where('aggregate_type', 'fleetops.maintenance_due')->count())->toBe(0);
});
