<?php

declare(strict_types=1);

namespace Logistics\Execution\Domain\ValueObject;

/**
 * Gravedad del incidente que reporta el chofer con su camión.
 *
 * Gobierna la reacción automática: un incidente MAYOR deja el camión fuera de servicio y reprograma las
 * paradas pendientes para el día siguiente; uno MENOR solo empuja las ETAs (sigue la ruta con retraso).
 */
enum IncidentSeverity: string
{
    /** Leve: avería o golpe menor; la ruta continúa con retraso (se empujan las ETAs pendientes). */
    case Minor = 'MINOR';

    /** Grave: accidente o avería que inmoviliza el camión; se reprograma el resto para el día siguiente. */
    case Major = 'MAJOR';

    /**
     * Texto en inglés para el admin y la consola (regla 5 de CLAUDE.md).
     */
    public function label(): string
    {
        return match ($this) {
            self::Minor => 'Minor',
            self::Major => 'Major',
        };
    }
}
