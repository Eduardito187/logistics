<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Domain\ValueObject;

/**
 * Estado de una orden de mantenimiento dentro de su ciclo de vida. Las transiciones válidas las protege
 * el agregado MaintenanceOrder; el enum solo enumera los estados posibles.
 */
enum MaintenanceStatus: string
{
    /** Programada: agendada pero aún no iniciada en el taller. */
    case Scheduled = 'scheduled';

    /** En curso: el vehículo está en el taller. */
    case InProgress = 'in_progress';

    /** Completada: trabajo terminado, con ítems y costo total. */
    case Completed = 'completed';

    /** Cancelada: la orden no se ejecutará. */
    case Cancelled = 'cancelled';

    /**
     * Texto en inglés para el admin y la API (regla de texto visible en inglés).
     */
    public function label(): string
    {
        return match ($this) {
            self::Scheduled => 'Scheduled',
            self::InProgress => 'In progress',
            self::Completed => 'Completed',
            self::Cancelled => 'Cancelled',
        };
    }
}
