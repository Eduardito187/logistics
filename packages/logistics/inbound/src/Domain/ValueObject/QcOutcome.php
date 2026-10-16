<?php

declare(strict_types=1);

namespace Logistics\Inbound\Domain\ValueObject;

/**
 * Resultado del control de calidad de una línea recibida.
 *
 * `Accepted` = todo lo recibido entra al stock; `Rejected` = nada entra (se devuelve al seller);
 * `PartialDamage` = parte entra y parte se rechaza por daño (ver ReceivedQuantity).
 */
enum QcOutcome: string
{
    /** Aceptada: lo recibido pasa el control de calidad. */
    case Accepted = 'accepted';

    /** Rechazada: lo recibido no pasa el control de calidad. */
    case Rejected = 'rejected';

    /** Daño parcial: parte aceptada, parte rechazada por daño. */
    case PartialDamage = 'partial_damage';

    /**
     * Texto en inglés para el admin y la consola (regla 5 de CLAUDE.md).
     */
    public function label(): string
    {
        return match ($this) {
            self::Accepted => 'Accepted',
            self::Rejected => 'Rejected',
            self::PartialDamage => 'Partial damage',
        };
    }
}
