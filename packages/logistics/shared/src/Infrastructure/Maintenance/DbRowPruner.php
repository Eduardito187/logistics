<?php

declare(strict_types=1);

namespace Logistics\Shared\Infrastructure\Maintenance;

use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Query\Builder;
use Logistics\Shared\Domain\Maintenance\PruneReport;
use Logistics\Shared\Domain\Maintenance\PruneSpec;
use Logistics\Shared\Domain\Maintenance\RowPruner;

/**
 * Implementación del puerto de poda contra la base relacional (PostgreSQL) vía el query builder.
 *
 * Seguridad por diseño:
 *  - SIEMPRE cuenta primero cuántas filas calzan (para log y para el dry-run).
 *  - En dry-run NO borra: devuelve el conteo y corta.
 *  - El borrado es CHUNKED: selecciona los ids del lote más viejo (usando el índice temporal) y los
 *    borra por `id`, lote a lote, para no bloquear la tabla ni inflar un solo DELETE gigante.
 *  - Es IDEMPOTENTE: cada corrida borra lo que quede bajo el umbral; al no quedar nada, borra 0.
 *  - Nunca borra fuera de la ventana temporal (y, si se pide, fuera del filtro de estado): el WHERE es
 *    siempre el mismo tanto para contar como para seleccionar los lotes.
 *
 * Asume que la tabla tiene una columna `id` (PK) para acotar el DELETE del lote; todas las tablas
 * objetivo (access_audit_log, outbox_messages, telemetry_pings) la tienen.
 */
final readonly class DbRowPruner implements RowPruner
{
    public function __construct(private ConnectionResolverInterface $connections) {}

    public function prune(PruneSpec $spec): PruneReport
    {
        $connection = $this->connections->connection();

        // Conteo previo con el MISMO criterio del borrado: sirve al log y al dry-run.
        $matched = (int) $this->criteria($connection->table($spec->table), $spec)->count();

        if ($spec->dryRun) {
            return new PruneReport($spec->label, $spec->table, $matched, 0, true, 0);
        }

        $deleted = 0;
        $chunks = 0;

        do {
            /** @var list<mixed> $ids */
            $ids = $this->criteria($connection->table($spec->table), $spec)
                ->orderBy($spec->timeColumn)
                ->limit($spec->chunkSize)
                ->pluck('id')
                ->all();

            if ($ids === []) {
                break;
            }

            $deleted += (int) $connection->table($spec->table)->whereIn('id', $ids)->delete();
            $chunks++;

            // Pausa opcional entre lotes: da aire al motor bajo carga de producción.
            if ($spec->pauseMicroseconds > 0) {
                usleep($spec->pauseMicroseconds);
            }
        } while (count($ids) === $spec->chunkSize);

        return new PruneReport($spec->label, $spec->table, $matched, $deleted, false, $chunks);
    }

    /**
     * Aplica la ventana temporal y el filtro de estado (si lo hay) al query builder dado.
     */
    private function criteria(Builder $query, PruneSpec $spec): Builder
    {
        $query->where($spec->timeColumn, '<', $spec->cutoff);

        if ($spec->statusColumn !== null && $spec->statusValues !== []) {
            $query->whereIn($spec->statusColumn, $spec->statusValues);
        }

        return $query;
    }
}
