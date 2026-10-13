<?php

declare(strict_types=1);

namespace Logistics\Planning\Domain\ValueObject;

/**
 * Estado de una corrida del auto-planificador diario. La corrida es ASÍNCRONA: se encola (Queued), un
 * worker la toma (Processing) y termina (Completed) o falla (Failed). El estado da la idempotencia:
 * re-disparar el mismo día+modo no vuelve a planificar si ya hay una corrida activa o completada.
 */
enum AutoPlanRunStatus: string
{
    /** Encolada: el comando dejó el trabajo en la cola, todavía sin procesar. */
    case Queued = 'queued';

    /** En proceso: un worker está armando los planes. */
    case Processing = 'processing';

    /** Terminada: los planes del día quedaron propuestos. */
    case Completed = 'completed';

    /** Falló: el worker no pudo completar la planificación (ver el motivo). */
    case Failed = 'failed';

    /**
     * Etiqueta en inglés para el admin y la API (regla 5 de CLAUDE.md).
     */
    public function label(): string
    {
        return match ($this) {
            self::Queued => 'Queued',
            self::Processing => 'Processing',
            self::Completed => 'Completed',
            self::Failed => 'Failed',
        };
    }

    /**
     * Una corrida activa o ya completada bloquea una nueva para el mismo día+modo (idempotencia).
     * Solo una corrida fallida (o la ausencia de corrida) habilita volver a disparar.
     */
    public function blocksNewRun(): bool
    {
        return $this !== self::Failed;
    }
}
