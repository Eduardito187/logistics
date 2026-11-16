<?php

declare(strict_types=1);

namespace Logistics\Fleet\Presentation\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Logistics\Fleet\Application\Contract\CrewReconciler;

/**
 * Sanea las tripulaciones duplicadas de datos viejos: un chofer tiene un solo camión por día y un camión
 * una sola tripulación por día. Por cada chofer + fecha (y cada vehículo + fecha) con más de una vigente
 * deja la MÁS RECIENTE y pasa las demás al historial (`fleet_crew_history`), sin borrar rastro.
 *
 * Simulación por defecto; `--force` aplica y, si no quedan duplicados, asegura los índices únicos.
 * Idempotente: sin duplicados no cambia nada. No reemite eventos al outbox. Lo corre el arranque y el
 * deploy después de migrar.
 */
final class ReconcileCrewsCommand extends Command
{
    protected $signature = 'logistics:fleet:reconcile-crews
        {--force : Aplica los cambios (sin esto es simulación)}
        {--chunk=200 : Grupos duplicados por lote}';

    protected $description = 'Deja una sola tripulación vigente por chofer y por vehículo en cada fecha (la más reciente)';

    public function handle(CrewReconciler $reconciler): int
    {
        if (!Schema::hasTable('fleet_crews') || !Schema::hasTable('fleet_crew_history')) {
            $this->warn('Faltan las tablas de tripulaciones o de su historial (falta migrar). No se hizo nada.');

            return self::SUCCESS;
        }

        $force = $this->option('force') === true;
        $report = $reconciler->reconcile($force, max(1, (int) $this->option('chunk')));

        $this->info(sprintf(
            '%s: %d chofer/fecha con duplicados (%d tripulaciones %s), %d vehículo/fecha con duplicados (%d tripulaciones %s). Índices únicos: %s.',
            $force ? 'Aplicado' : 'Simulación',
            $report['driver_groups'],
            $report['driver_crews'],
            $force ? 'pasadas al historial' : 'a pasar al historial',
            $report['vehicle_groups'],
            $report['vehicle_crews'],
            $force ? 'pasadas al historial' : 'a pasar al historial',
            $report['indexes_ready'] ? 'listos' : 'pendientes',
        ));

        if (!$force && ($report['driver_groups'] > 0 || $report['vehicle_groups'] > 0)) {
            $this->line('Para aplicar: php artisan logistics:fleet:reconcile-crews --force');
        }

        return self::SUCCESS;
    }
}
