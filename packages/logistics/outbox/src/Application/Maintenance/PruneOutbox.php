<?php

declare(strict_types=1);

namespace Logistics\Outbox\Application\Maintenance;

use DateInterval;
use Logistics\Outbox\Domain\ValueObject\OutboxStatus;
use Logistics\Shared\Domain\Clock\Clock;
use Logistics\Shared\Domain\Maintenance\PruneReport;
use Logistics\Shared\Domain\Maintenance\PruneSpec;
use Logistics\Shared\Domain\Maintenance\RowPruner;

/**
 * Caso de uso: podar el outbox (`outbox_messages`), tabla de alta rotación que crece sin tope.
 *
 * SOLO toca estados TERMINALES, nunca despachables:
 *  - `dispatched`: entregados con éxito; se podan por `dispatched_at` pasados N días (ventana corta: ya
 *    cumplieron su función). Son la inmensa mayoría a escala.
 *  - `dead_lettered`: agotaron reintentos; se RETIENEN más tiempo por `created_at` (ventana larga) para
 *    diagnóstico post-mortem.
 *
 * NUNCA borra `pending` ni `failed` (OutboxStatus::dispatchable()): son trabajo aún por despachar y
 * perderlos rompería la entrega at-least-once. El filtro de estado del spec lo garantiza explícitamente.
 */
final readonly class PruneOutbox
{
    public function __construct(
        private RowPruner $pruner,
        private Clock $clock,
    ) {}

    /**
     * @return list<PruneReport> Un reporte por cada estado terminal podado (dispatched, dead_lettered).
     */
    public function handle(
        int $dispatchedRetentionDays,
        int $deadLetteredRetentionDays,
        int $chunkSize,
        bool $dryRun,
        int $pauseMicroseconds = 0,
    ): array {
        $now = $this->clock->now();

        // Despachados: se miden desde que se despacharon (dispatched_at siempre presente en este estado).
        $dispatched = $this->pruner->prune(new PruneSpec(
            label: 'outbox dispatched',
            table: 'outbox_messages',
            timeColumn: 'dispatched_at',
            cutoff: $now->sub(new DateInterval('P'.max(0, $dispatchedRetentionDays).'D')),
            chunkSize: $chunkSize,
            dryRun: $dryRun,
            pauseMicroseconds: $pauseMicroseconds,
            statusColumn: 'status',
            statusValues: [OutboxStatus::Dispatched->value],
        ));

        // Dead-letter: nunca se despachó (dispatched_at es nulo), así que se mide por created_at.
        $deadLettered = $this->pruner->prune(new PruneSpec(
            label: 'outbox dead-lettered',
            table: 'outbox_messages',
            timeColumn: 'created_at',
            cutoff: $now->sub(new DateInterval('P'.max(0, $deadLetteredRetentionDays).'D')),
            chunkSize: $chunkSize,
            dryRun: $dryRun,
            pauseMicroseconds: $pauseMicroseconds,
            statusColumn: 'status',
            statusValues: [OutboxStatus::DeadLettered->value],
        ));

        return [$dispatched, $deadLettered];
    }
}
