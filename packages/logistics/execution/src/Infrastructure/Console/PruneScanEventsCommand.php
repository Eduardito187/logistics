<?php

declare(strict_types=1);

namespace Logistics\Execution\Infrastructure\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Logistics\Execution\Application\Maintenance\PruneScanEvents;

/**
 * C7 — Poda los escaneos en terreno viejos (`execution_scan_events`), tabla append-only de alto volumen.
 *
 * Retención generosa y configurable (config logistics-execution.prune.scan_events_retention_days;
 * --days la sobreescribe). SEGURO POR DEFECTO: sin --force hace dry-run. Borrado CHUNKED e idempotente
 * por `occurred_at`. No se agenda en routes/console.php (retención manual/ops, como las demás podas).
 */
final class PruneScanEventsCommand extends Command
{
    protected $signature = 'logistics:execution:prune-scan-events
        {--days= : Retention window in days (defaults to config logistics-execution.prune.scan_events_retention_days)}
        {--chunk= : Rows to delete per batch (defaults to config logistics-execution.prune.chunk)}
        {--pause= : Pause between batches in milliseconds (defaults to config logistics-execution.prune.pause_ms)}
        {--dry-run : Only count and report, never delete (this is the DEFAULT)}
        {--force : Actually delete; without this flag the command runs as a dry-run}';

    protected $description = 'Prune old execution scan events beyond the retention window (dry-run by default; --force to delete)';

    public function handle(PruneScanEvents $pruner, Repository $config): int
    {
        $daysOption = $this->option('days');
        $days = $daysOption === null
            ? (int) $config->get('logistics-execution.prune.scan_events_retention_days', 365)
            : (int) $daysOption;

        $chunkOption = $this->option('chunk');
        $chunk = $chunkOption === null
            ? (int) $config->get('logistics-execution.prune.chunk', 5000)
            : (int) $chunkOption;

        if ($chunk < 1) {
            $this->error('--chunk debe ser >= 1.');

            return self::FAILURE;
        }

        $pauseOption = $this->option('pause');
        $pauseMs = $pauseOption === null
            ? (int) $config->get('logistics-execution.prune.pause_ms', 100)
            : (int) $pauseOption;

        // Dry-run por defecto: solo borra con --force y SIN --dry-run explícito (ante la duda, no borra).
        $dryRun = (bool) $this->option('dry-run') || !(bool) $this->option('force');

        $report = $pruner->handle($days, $chunk, $dryRun, max(0, $pauseMs) * 1000);

        if ($dryRun) {
            $this->warn(sprintf(
                '[DRY-RUN] %s: %d fila(s) con occurred_at anterior a %d día(s) se borrarían. Use --force para borrar.',
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
