<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Infrastructure\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Logistics\Telemetry\Application\Maintenance\PruneTelemetryPings;

/**
 * Poda los pings GPS crudos (`telemetry_pings`), la tabla de mayor crecimiento.
 *
 * El RAW viejo es desechable: trips, posición en vivo y analítica de calles persisten aparte. Retención
 * corta configurable (config logistics-telemetry.prune.pings_retention_days; --days la sobreescribe).
 * SEGURO POR DEFECTO: sin --force hace dry-run. Borrado CHUNKED e idempotente por `recorded_at`.
 *
 * NO poda telemetry_vehicle_positions: es la proyección EN VIVO (una fila por vehículo, pisada en cada
 * ping), acotada por la cantidad de vehículos; no crece con el tiempo y borrarla perdería la posición.
 */
final class PruneTelemetryPingsCommand extends Command
{
    protected $signature = 'logistics:telemetry:prune-pings
        {--days= : Retention window in days (defaults to config logistics-telemetry.prune.pings_retention_days)}
        {--chunk= : Rows to delete per batch (defaults to config logistics-telemetry.prune.chunk)}
        {--pause= : Pause between batches in milliseconds (defaults to config logistics-telemetry.prune.pause_ms)}
        {--dry-run : Only count and report, never delete (this is the DEFAULT)}
        {--force : Actually delete; without this flag the command runs as a dry-run}';

    protected $description = 'Prune old raw telemetry pings beyond the retention window (dry-run by default; --force to delete)';

    public function handle(PruneTelemetryPings $pruner, Repository $config): int
    {
        $daysOption = $this->option('days');
        $days = $daysOption === null
            ? (int) $config->get('logistics-telemetry.prune.pings_retention_days', 60)
            : (int) $daysOption;

        $chunkOption = $this->option('chunk');
        $chunk = $chunkOption === null
            ? (int) $config->get('logistics-telemetry.prune.chunk', 5000)
            : (int) $chunkOption;

        if ($chunk < 1) {
            $this->error('--chunk debe ser >= 1.');

            return self::FAILURE;
        }

        $pauseOption = $this->option('pause');
        $pauseMs = $pauseOption === null
            ? (int) $config->get('logistics-telemetry.prune.pause_ms', 100)
            : (int) $pauseOption;

        // Dry-run por defecto: solo borra con --force y SIN --dry-run explícito (ante la duda, no borra).
        $dryRun = (bool) $this->option('dry-run') || !(bool) $this->option('force');

        $report = $pruner->handle($days, $chunk, $dryRun, max(0, $pauseMs) * 1000);

        if ($dryRun) {
            $this->warn(sprintf(
                '[DRY-RUN] %s: %d fila(s) con recorded_at anterior a %d día(s) se borrarían. Use --force para borrar.',
                $report->table,
                $report->matched,
                $days,
            ));
        } else {
            $this->info(sprintf(
                '%s: %d fila(s) borradas (de %d candidatas) en %d lote(s), retención %d día(s).',
                $report->table,
                $report->deleted,
                $report->matched,
                $report->chunks,
                $days,
            ));
        }

        return self::SUCCESS;
    }
}
