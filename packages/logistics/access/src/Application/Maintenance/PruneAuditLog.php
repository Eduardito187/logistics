<?php

declare(strict_types=1);

namespace Logistics\Access\Application\Maintenance;

use DateInterval;
use Logistics\Shared\Domain\Clock\Clock;
use Logistics\Shared\Domain\Maintenance\PruneReport;
use Logistics\Shared\Domain\Maintenance\PruneSpec;
use Logistics\Shared\Domain\Maintenance\RowPruner;

/**
 * Caso de uso: podar la bitácora de auditoría de accesos (`access_audit_log`), el "log" en base de datos.
 *
 * Es el registro de acciones sensibles de administración; su retención por defecto es GENEROSA (p. ej.
 * 180 días) porque tiene valor de trazabilidad. Se poda únicamente por antigüedad (`occurred_at`): no
 * filtra por tipo de acción, de modo que el borrado es predecible y auditable.
 *
 * Application puro: recibe la ventana y los parámetros de borrado, calcula el umbral con el reloj del
 * dominio y delega el borrado CHUNKED en el puerto RowPruner.
 */
final readonly class PruneAuditLog
{
    public function __construct(
        private RowPruner $pruner,
        private Clock $clock,
    ) {}

    public function handle(int $retentionDays, int $chunkSize, bool $dryRun, int $pauseMicroseconds = 0): PruneReport
    {
        $cutoff = $this->clock->now()->sub(new DateInterval('P'.max(0, $retentionDays).'D'));

        return $this->pruner->prune(new PruneSpec(
            label: 'access audit log',
            table: 'access_audit_log',
            timeColumn: 'occurred_at',
            cutoff: $cutoff,
            chunkSize: $chunkSize,
            dryRun: $dryRun,
            pauseMicroseconds: $pauseMicroseconds,
        ));
    }
}
