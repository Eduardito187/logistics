<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Domain\ValueObject;

/**
 * Estado de un trip (tramo continuo de conducción de un vehículo).
 *
 * Es el dominio quien decide las transiciones (ver Trip); el enum solo enumera los estados válidos.
 */
enum TripStatus: string
{
    /** Activo: el vehículo sigue reportando pings dentro del hueco temporal del trip. */
    case Active = 'active';

    /** Completado: cerrado por inactividad (gap) o por fin de jornada; ya no acumula puntos. */
    case Completed = 'completed';

    /**
     * Texto en inglés para el admin y la consola (regla 5 de CLAUDE.md).
     */
    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Completed => 'Completed',
        };
    }
}
