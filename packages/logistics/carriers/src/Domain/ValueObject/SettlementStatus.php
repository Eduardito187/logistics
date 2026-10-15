<?php

declare(strict_types=1);

namespace Logistics\Carriers\Domain\ValueObject;

/**
 * Estado del ciclo de vida de una liquidación de transportista (P2-CAR-04).
 *
 * El dominio decide las transiciones (ver Settlement): el transportista la envía (Submitted); la
 * operación la aprueba (Approved) y luego la marca pagada (Paid), o la objeta (Disputed) si hay
 * discrepancias. El enum solo enumera los estados válidos.
 */
enum SettlementStatus: string
{
    /** Enviada por el transportista, pendiente de revisión. */
    case Submitted = 'submitted';

    /** Aprobada por la operación, pendiente de pago. */
    case Approved = 'approved';

    /** Pagada: cerrada. */
    case Paid = 'paid';

    /** Objetada: hay una discrepancia que resolver antes de aprobar. */
    case Disputed = 'disputed';

    /**
     * Texto en inglés para el admin y la consola (regla 5 de CLAUDE.md).
     */
    public function label(): string
    {
        return match ($this) {
            self::Submitted => 'Submitted',
            self::Approved => 'Approved',
            self::Paid => 'Paid',
            self::Disputed => 'Disputed',
        };
    }
}
