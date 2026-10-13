<?php

declare(strict_types=1);

namespace Logistics\Execution\Domain\ValueObject;

/**
 * Tipo del incidente que reporta el chofer: avería mecánica o accidente.
 *
 * Es descriptivo (para supervisión y reportes); la reacción automática la decide la gravedad
 * (IncidentSeverity), no el tipo.
 */
enum IncidentType: string
{
    /** Avería: el camión se descompone (mecánica, eléctrica, neumático…). */
    case Breakdown = 'BREAKDOWN';

    /** Accidente: colisión o siniestro en la vía. */
    case Accident = 'ACCIDENT';

    /**
     * Texto en inglés para el admin y la consola (regla 5 de CLAUDE.md).
     */
    public function label(): string
    {
        return match ($this) {
            self::Breakdown => 'Breakdown',
            self::Accident => 'Accident',
        };
    }
}
