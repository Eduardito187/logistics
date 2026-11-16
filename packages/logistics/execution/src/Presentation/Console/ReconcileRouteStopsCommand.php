<?php

declare(strict_types=1);

namespace Logistics\Execution\Presentation\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Logistics\Execution\Application\Contract\RouteStopsReconciler;

/**
 * Sanea las paradas que quedaron de más en las rutas por datos viejos: antes la asignación de un pedido a un
 * camión caía en «cualquier ruta abierta» (hasta una de otro día sin cerrar), así que una ruta arrastraba
 * paradas de varias jornadas y de pedidos ya entregados, y «mi ruta de hoy» mostraba decenas de paradas que
 * no eran del día. Deja en cada ruta NO cerrada solo las paradas del plan publicado de ese camión/día más
 * las sumadas ese día, y recalcula los contadores.
 *
 * Simulación por defecto; `--force` aplica. Conservador: no toca rutas cerradas ni paradas con actividad
 * registrada ese mismo día (llegada, cierre o escaneo). Idempotente y sin reemitir eventos al outbox. Lo
 * corre el arranque y el deploy después de migrar.
 */
final class ReconcileRouteStopsCommand extends Command
{
    protected $signature = 'logistics:execution:reconcile-route-stops
        {--force : Aplica los cambios (sin esto es simulación)}
        {--chunk=200 : Rutas por lote}';

    protected $description = 'Deja en cada ruta no cerrada solo las paradas del plan de ese camión/día más las sumadas ese día';

    public function handle(RouteStopsReconciler $reconciler): int
    {
        if (!Schema::hasTable('execution_driver_routes') || !Schema::hasTable('execution_driver_route_stops')) {
            $this->warn('Faltan las tablas de rutas o de sus paradas (falta migrar). No se hizo nada.');

            return self::SUCCESS;
        }

        $force = $this->option('force') === true;
        $report = $reconciler->reconcile($force, (int) $this->option('chunk'));

        $this->info(sprintf(
            '%s: %d rutas no cerradas revisadas, %d con paradas de más, %d paradas %s.',
            $force ? 'Aplicado' : 'Simulación',
            $report['routes_scanned'],
            $report['routes_touched'],
            $report['stops_removed'],
            $force ? 'quitadas' : 'a quitar',
        ));

        if (!$force && $report['stops_removed'] > 0) {
            $this->line('Para aplicar: php artisan logistics:execution:reconcile-route-stops --force');
        }

        return self::SUCCESS;
    }
}
