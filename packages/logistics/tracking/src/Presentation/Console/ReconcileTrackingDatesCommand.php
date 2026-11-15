<?php

declare(strict_types=1);

namespace Logistics\Tracking\Presentation\Console;

use Illuminate\Console\Command;
use Logistics\Tracking\Application\Contract\TrackingDatesReconciler;

/**
 * Pone al día `at_store_at` (pedidos que ya estaban en su tienda) y corrige las `delivered_at` guardadas
 * con el corrimiento del desplazamiento horario. Simulación por defecto; `--force` aplica.
 *
 * Idempotente y sin eventos: se engancha al arranque y al deploy, así un entorno nuevo o uno con datos
 * viejos queda igual sin pasos manuales.
 */
final class ReconcileTrackingDatesCommand extends Command
{
    protected $signature = 'logistics:tracking:reconcile-dates
        {--force : Apply the changes (without it, only counts what would change)}';

    protected $description = 'Fill at_store_at for orders already at their pickup store and fix delivered_at values shifted by the timezone offset (idempotent)';

    public function handle(TrackingDatesReconciler $reconciler): int
    {
        $apply = $this->option('force') === true;
        $result = $reconciler->run($apply);

        $this->table(
            ['Case', $apply ? 'Rows updated' : 'Rows to update'],
            [
                ['Arrival at the pickup store (at_store_at)', $result['at_store']],
                ['Delivery date shifted by the timezone offset (delivered_at)', $result['delivered_shifted']],
            ],
        );

        $this->info($apply ? 'Done.' : 'Dry run: nothing was changed. Use --force to apply.');

        return self::SUCCESS;
    }
}
