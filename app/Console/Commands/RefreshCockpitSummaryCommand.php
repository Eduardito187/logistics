<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\DatabaseManager;

/**
 * Refresca la vista materializada `cockpit_daily_summary` que alimenta la Torre de Control.
 *
 * Ola 3: la vista materializada pre-calcula las agregaciones pesadas (COUNTs sobre shipments,
 * execution_deliveries, telemetry_alerts, outbox_messages) una sola vez, y todos los cockpits leen de ella
 * en vez de calcular por request. REFRESH CONCURRENTLY no bloquea las lecturas: los usuarios siguen viendo
 * la versión anterior mientras se actualiza.
 *
 * El scheduler lo invoca cada 30 segundos con `onOneServer()` para no duplicar el trabajo en múltiples
 * instancias. También se puede invocar a mano.
 */
final class RefreshCockpitSummaryCommand extends Command
{
    protected $signature = 'logistics:cockpit:refresh';

    protected $description = 'Refresh the cockpit_daily_summary materialized view (non-blocking, concurrent)';

    public function handle(DatabaseManager $db): int
    {
        $connection = $db->connection();

        if ($connection->getDriverName() !== 'pgsql') {
            $this->warn('logistics:cockpit:refresh solo aplica a PostgreSQL; se omite en este driver.');

            return self::SUCCESS;
        }

        // Verificar que la vista existe (entorno sin migrar o en testing con SQLite).
        $exists = $connection->selectOne(
            "SELECT 1 FROM pg_matviews WHERE matviewname = 'cockpit_daily_summary'",
        );
        if ($exists === null) {
            $this->warn('La vista cockpit_daily_summary no existe; se omite el refresco.');

            return self::SUCCESS;
        }

        $start = microtime(true);
        $connection->statement('REFRESH MATERIALIZED VIEW CONCURRENTLY cockpit_daily_summary');
        $elapsedMs = (microtime(true) - $start) * 1000;

        $this->info(sprintf('cockpit_daily_summary refrescada en %.1f ms.', $elapsedMs));

        return self::SUCCESS;
    }
}
