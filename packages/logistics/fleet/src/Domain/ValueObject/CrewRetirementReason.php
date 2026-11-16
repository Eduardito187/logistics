<?php

declare(strict_types=1);

namespace Logistics\Fleet\Domain\ValueObject;

/**
 * Motivo por el que una tripulación dejó de estar vigente. Queda grabado en su rastro histórico.
 */
enum CrewRetirementReason: string
{
    /** El mismo chofer pasó a otra tripulación ese día (otro camión, otros ayudantes u otro turno). */
    case Replaced = 'replaced';

    /** Otro chofer se quedó con el camión ese día (reemplazo confirmado). */
    case Displaced = 'displaced';

    /** Se quitó la asignación a mano. */
    case Disbanded = 'disbanded';
}
