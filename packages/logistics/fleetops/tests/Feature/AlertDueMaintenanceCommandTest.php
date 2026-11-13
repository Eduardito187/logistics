<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Logistics\FleetOps\Infrastructure\Persistence\Eloquent\Factory\MaintenancePlanFactory;

/**
 * Cobertura del comando de consola de alertas de mantenimiento (Patrón consola del brief). Delega en el
 * handler, que barre los planes activos por vencer/vencido y emite (o no) una alerta al outbox; el comando
 * imprime el resumen de la corrida. Se cubren ambas ramas: sin planes due (skipped) y con un plan vencido
 * (emitted → fila en el outbox). No caza bugs: ejercita el comando contra logistics_test.
 */
beforeEach(function (): void {})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

it('corre el barrido y reporta "skipped" cuando no hay planes por vencer', function (): void {
    $this->artisan('logistics:fleetops:maintenance-alerts')
        ->expectsOutputToContain('skipped (nothing to report)')
        ->assertExitCode(0);
});

it('corre el barrido, reporta "emitted" y encola la alerta cuando hay un plan vencido', function (): void {
    MaintenancePlanFactory::new()->create([
        'interval_km' => 10_000,
        'interval_days' => 180,
        'next_due_km' => 105_000,
        'next_due_at' => '2020-01-01 09:00:00',
        'active' => true,
    ]);

    $this->artisan('logistics:fleetops:maintenance-alerts')
        ->expectsOutputToContain('alert emitted')
        ->assertExitCode(0);

    // El handler persiste un único evento de resumen en el outbox (aggregate_type del contexto).
    expect(DB::table('outbox_messages')->where('event_name', 'fleetops.maintenance_due_alerted')->count())
        ->toBeGreaterThanOrEqual(1);
});
