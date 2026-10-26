<?php

declare(strict_types=1);

namespace Logistics\Shared\Domain\Maintenance;

/**
 * Resultado de una poda: cuántas filas calzaban con el criterio y cuántas se borraron de verdad.
 *
 * En dry-run `deleted` es 0 y `matched` dice cuántas se borrarían. Fuera de dry-run ambos deberían
 * coincidir salvo filas insertadas/borradas en paralelo durante el barrido. Se usa para loguear la
 * corrida (requisito de seguridad: siempre reportar el impacto por tabla).
 */
final readonly class PruneReport
{
    public function __construct(
        public string $label,
        public string $table,
        public int $matched,
        public int $deleted,
        public bool $dryRun,
        public int $chunks,
    ) {}

    /**
     * Filas que la corrida afectó (dry-run: las que afectaría; real: las borradas).
     */
    public function affected(): int
    {
        return $this->dryRun ? $this->matched : $this->deleted;
    }
}
