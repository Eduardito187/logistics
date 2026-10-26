<?php

declare(strict_types=1);

namespace Logistics\Shared\Domain\Maintenance;

/**
 * Puerto de poda de retención. El Application de cada contexto arma su PruneSpec y pide el borrado por
 * acá; la implementación de infraestructura hace el borrado CHUNKED e idempotente contra la base.
 *
 * Mantener este contrato en Domain permite que el caso de uso (Application) sea PHP puro y testeable, y
 * que el detalle del motor (PostgreSQL, Eloquent) viva aislado en Infrastructure.
 */
interface RowPruner
{
    /**
     * Ejecuta (o simula, si el spec es dry-run) la poda descrita y devuelve su resultado.
     */
    public function prune(PruneSpec $spec): PruneReport;
}
