<?php

declare(strict_types=1);

namespace Logistics\Execution\Application\Maintenance;

use DateInterval;
use Logistics\Shared\Domain\Clock\Clock;
use Logistics\Shared\Domain\Maintenance\PruneReport;
use Logistics\Shared\Domain\Maintenance\PruneSpec;
use Logistics\Shared\Domain\Maintenance\RowPruner;

/**
 * C7 — Caso de uso: podar los escaneos en terreno viejos (`execution_scan_events`), tabla append-only de
 * alto volumen a escala.
 *
 * El escaneo individual es un hecho histórico; pasada una ventana de retención generosa (su valor
 * operativo —prueba de entrega, trazabilidad— ya se consolidó en las entregas/rutas), el registro crudo
 * puede archivarse/podarse para que la tabla no crezca sin techo. Se poda solo por antigüedad
 * (`occurred_at`), que cuenta con el índice BRIN para el barrido por rango.
 *
 * Reusa el `RowPruner` compartido (borrado CHUNKED e idempotente), igual que la poda de pings GPS.
 */
final readonly class PruneScanEvents
{
    public function __construct(
        private RowPruner $pruner,
        private Clock $clock,
    ) {}

    public function handle(int $retentionDays, int $chunkSize, bool $dryRun, int $pauseMicroseconds = 0): PruneReport
    {
        $cutoff = $this->clock->now()->sub(new DateInterval('P'.max(0, $retentionDays).'D'));

        return $this->pruner->prune(new PruneSpec(
            label: 'execution scan events',
            table: 'execution_scan_events',
            timeColumn: 'occurred_at',
            cutoff: $cutoff,
            chunkSize: $chunkSize,
            dryRun: $dryRun,
            pauseMicroseconds: $pauseMicroseconds,
        ));
    }
}
