<?php

declare(strict_types=1);

namespace Logistics\Outbox\Presentation\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Logistics\Outbox\Application\Maintenance\PruneOutbox;

/**
 * Poda el outbox (`outbox_messages`) por retención. SOLO estados terminales:
 *  - dispatched: ventana corta (config logistics-outbox.prune.dispatched_days; --days la sobreescribe).
 *  - dead_lettered: ventana larga (config logistics-outbox.prune.dead_lettered_days) para diagnóstico.
 *
 * NUNCA toca pending/failed. SEGURO POR DEFECTO: sin --force hace dry-run (cuenta y reporta, no borra).
 * Borrado CHUNKED e idempotente. Lo invoca el scheduler (con --force) o se corre a mano.
 */
final class PruneOutboxCommand extends Command
{
    protected $signature = 'logistics:outbox:prune
        {--days= : Override the DISPATCHED retention window in days (defaults to config logistics-outbox.prune.dispatched_days)}
        {--dead-days= : Override the DEAD-LETTERED retention window in days (defaults to config logistics-outbox.prune.dead_lettered_days)}
        {--chunk= : Rows to delete per batch (defaults to config logistics-outbox.prune.chunk)}
        {--pause= : Pause between batches in milliseconds (defaults to config logistics-outbox.prune.pause_ms)}
        {--dry-run : Only count and report, never delete (this is the DEFAULT)}
        {--force : Actually delete; without this flag the command runs as a dry-run}';

    protected $description = 'Prune terminal outbox messages (dispatched, dead-lettered) beyond retention; never touches pending/failed (dry-run by default)';

    public function handle(PruneOutbox $pruner, Repository $config): int
    {
        $daysOption = $this->option('days');
        $dispatchedDays = $daysOption === null
            ? (int) $config->get('logistics-outbox.prune.dispatched_days', 14)
            : (int) $daysOption;

        $deadDaysOption = $this->option('dead-days');
        $deadLetteredDays = $deadDaysOption === null
            ? (int) $config->get('logistics-outbox.prune.dead_lettered_days', 90)
            : (int) $deadDaysOption;

        $chunkOption = $this->option('chunk');
        $chunk = $chunkOption === null
            ? (int) $config->get('logistics-outbox.prune.chunk', 5000)
            : (int) $chunkOption;

        if ($chunk < 1) {
            $this->error('--chunk debe ser >= 1.');

            return self::FAILURE;
        }

        $pauseOption = $this->option('pause');
        $pauseMs = $pauseOption === null
            ? (int) $config->get('logistics-outbox.prune.pause_ms', 100)
            : (int) $pauseOption;

        // Dry-run por defecto: solo borra con --force y SIN --dry-run explícito (ante la duda, no borra).
        $dryRun = (bool) $this->option('dry-run') || !(bool) $this->option('force');

        $reports = $pruner->handle($dispatchedDays, $deadLetteredDays, $chunk, $dryRun, max(0, $pauseMs) * 1000);

        foreach ($reports as $report) {
            if ($dryRun) {
                $this->warn(sprintf(
                    '[DRY-RUN] %s: %d fila(s) se borrarían. Use --force para borrar.',
                    $report->label,
                    $report->matched,
                ));
            } else {
                $this->info(sprintf(
                    '%s: %d fila(s) borradas (de %d candidatas) en %d lote(s).',
                    $report->label,
                    $report->deleted,
                    $report->matched,
                    $report->chunks,
                ));
            }
        }

        $this->line(sprintf(
            'Retención: dispatched %d día(s), dead-lettered %d día(s). pending/failed NUNCA se podan.',
            $dispatchedDays,
            $deadLetteredDays,
        ));

        return self::SUCCESS;
    }
}
