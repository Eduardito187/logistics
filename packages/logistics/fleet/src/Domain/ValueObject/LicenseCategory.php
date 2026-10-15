<?php

declare(strict_types=1);

namespace Logistics\Fleet\Domain\ValueObject;

/**
 * Categoría de licencia de conducir de Bolivia. Se usa de forma nullable: no todo chofer la tiene
 * registrada todavía.
 */
enum LicenseCategory: string
{
    /** Particular: vehículos livianos de uso personal. */
    case P = 'P';

    /** Profesional A: transporte de pasajeros liviano / taxi. */
    case A = 'A';

    /** Profesional B: camiones y transporte de carga. */
    case B = 'B';

    /** Profesional C: buses y transporte pesado de pasajeros. */
    case C = 'C';

    /** Motocicletas. */
    case M = 'M';

    /**
     * Texto en inglés para el admin y la consola (regla 5 de CLAUDE.md).
     */
    public function label(): string
    {
        return match ($this) {
            self::P => 'P — Private',
            self::A => 'A — Professional (light passenger)',
            self::B => 'B — Professional (cargo)',
            self::C => 'C — Professional (heavy passenger)',
            self::M => 'M — Motorcycle',
        };
    }
}
