<?php

declare(strict_types=1);

namespace Logistics\Access\Presentation\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Logistics\Access\Application\Maintenance\PruneAuditLog;

/**
 * Poda la bitácora de auditoría de accesos (`access_audit_log`) por retención.
 *
 * SEGURO POR DEFECTO: sin --force hace dry-run (solo cuenta y reporta, NO borra). La ventana de
 * retención es configurable (config logistics-access.prune / env) y se puede sobreescribir con --days.
 * El borrado es CHUNKED e idempotente. Lo invoca el scheduler (con --force) o se corre a mano.
 */
final class PruneAuditLogCommand extends Command
{
    protected $signature = 'logistics:access:prune-audit-log
        {--days= : Retention window in days (defaults to config logistics-access.prune.audit_log_retention_days)}
        {--chunk= : Rows to delete per batch (defaults to config logistics-access.prune.chunk)}
        {--pause= : Pause between batches in milliseconds (defaults to config logistics-access.prune.pause_ms)}
        {--dry-run : Only count and report, never delete (this is the DEFAULT)}
        {--force : Actually delete; without this flag the command runs as a dry-run}';

    protected $description = 'Prune old access audit log rows beyond the retention window (dry-run by default; --force to delete)';

    public function handle(PruneAuditLog $pruner, Repository $config): int
    {
        $daysOption = $this->option('days');
        $days = $daysOption === null
            ? (int) $config->get('logistics-access.prune.audit_log_retention_days', 180)
            : (int) $daysOption;

        $chunkOption = $this->option('chunk');
        $chunk = $chunkOption === null
            ? (int) $config->get('logistics-access.prune.chunk', 5000)
            : (int) $chunkOption;

        if ($chunk < 1) {
            $this->error('--chunk debe ser >= 1.');

            return self::FAILURE;
        }

        $pauseOption = $this->option('pause');
        $pauseMs = $pauseOption === null
            ? (int) $config->get('logistics-access.prune.pause_ms', 100)
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
