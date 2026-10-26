<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Application\Maintenance;

use DateInterval;
use Logistics\Shared\Domain\Clock\Clock;
use Logistics\Shared\Domain\Maintenance\PruneReport;
use Logistics\Shared\Domain\Maintenance\PruneSpec;
use Logistics\Shared\Domain\Maintenance\RowPruner;

/**
 * Caso de uso: podar los pings GPS crudos (`telemetry_pings`), la tabla que más crece (millones de filas).
 *
 * El RAW viejo es DESECHABLE: la información con valor persiste aparte —trips (telemetry_trips), la
 * posición en vivo (telemetry_vehicle_positions) y la analítica de calles (telemetry_street_stats)—, que
 * se derivan de los pings en el momento de la ingesta. Por eso la retención del RAW es CORTA y
 * configurable (p. ej. 30–90 días): pasada la ventana, el ping individual ya no aporta.
 *
 * Se poda solo por antigüedad (`recorded_at`, indexada); el barrido por rango temporal se beneficia del
 * índice sobre esa columna.
 */
final readonly class PruneTelemetryPings
{
    public function __construct(
        private RowPruner $pruner,
        private Clock $clock,
    ) {}

    public function handle(int $retentionDays, int $chunkSize, bool $dryRun, int $pauseMicroseconds = 0): PruneReport
    {
        $cutoff = $this->clock->now()->sub(new DateInterval('P'.max(0, $retentionDays).'D'));

        return $this->pruner->prune(new PruneSpec(
            label: 'telemetry pings (raw)',
            table: 'telemetry_pings',
            timeColumn: 'recorded_at',
            cutoff: $cutoff,
            chunkSize: $chunkSize,
            dryRun: $dryRun,
            pauseMicroseconds: $pauseMicroseconds,
        ));
    }
}
