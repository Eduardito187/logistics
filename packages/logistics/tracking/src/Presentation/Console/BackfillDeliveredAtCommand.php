<?php

declare(strict_types=1);

namespace Logistics\Tracking\Presentation\Console;

use Illuminate\Console\Command;
use Logistics\Tracking\Application\Contract\DeliveredAtBackfill;

/**
 * Completa `tracking_shipments.delivered_at` en los seguimientos ya entregados (filas previas a la columna).
 *
 * Idempotente y re-ejecutable: solo toca filas entregadas/devueltas con `delivered_at` vacío. Se corre UNA
 * vez tras desplegar la migración; después la proyección (ProjectExecutionTracking) lo mantiene sola, así
 * que no necesita entrada en el scheduler. `--without-updated-at` omite el último recurso aproximado.
 */
final class BackfillDeliveredAtCommand extends Command
{
    protected $signature = 'logistics:tracking:backfill-delivered-at
        {--without-updated-at : Do not fall back to updated_at for rows without a better source}';

    protected $description = 'Fill delivered_at for already delivered shipment trackings (idempotent)';

    public function handle(DeliveredAtBackfill $backfill): int
    {
        $result = $backfill->run(useUpdatedAtFallback: $this->option('without-updated-at') !== true);

        $this->table(
            ['Source', 'Rows filled'],
            [
                ['Execution deliveries', $result['execution']],
                ['Outbox: shipment delivered', $result['outbox_delivered']],
                ['Outbox: tracking status history', $result['status_history']],
                ['Fallback: last update (approximate)', $result['updated_at']],
            ],
        );

        $this->info('Done. Rows filled: '.array_sum($result).'.');

        return self::SUCCESS;
    }
}
