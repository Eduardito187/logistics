<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Domain\ValueObject;

/**
 * Estado de una salida/despacho de un vehículo de flota propia.
 *
 * Es el dominio quien decide las transiciones (ver Departure); el enum solo enumera los estados válidos.
 */
enum DepartureStatus: string
{
    /** En ruta: el vehículo salió y todavía no retornó. */
    case Out = 'out';

    /** Retornada: el vehículo volvió y se cerró la salida (con odómetro de regreso). */
    case Returned = 'returned';

    /**
     * Texto en inglés para el admin y la consola (regla 5 de CLAUDE.md).
     */
    public function label(): string
    {
        return match ($this) {
            self::Out => 'Out',
            self::Returned => 'Returned',
        };
    }
}
