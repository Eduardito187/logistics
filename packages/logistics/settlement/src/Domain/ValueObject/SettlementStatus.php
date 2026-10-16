<?php

declare(strict_types=1);

namespace Logistics\Settlement\Domain\ValueObject;

/**
 * Estado del ciclo de vida de una liquidación de seller.
 *
 * El dominio decide las transiciones (ver SellerSettlement): se arma como borrador (Draft), se confirma
 * (Confirmed) tras la revisión y se marca pagada (Paid) al liquidar al seller. El enum solo enumera los
 * estados válidos.
 */
enum SettlementStatus: string
{
    /** Borrador recién agregado del periodo, pendiente de revisión. */
    case Draft = 'draft';

    /** Confirmada por la operación, pendiente de pago. */
    case Confirmed = 'confirmed';

    /** Pagada al seller: cerrada. */
    case Paid = 'paid';

    /**
     * Texto en inglés para el admin y la consola (regla 5 de CLAUDE.md).
     */
    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Confirmed => 'Confirmed',
            self::Paid => 'Paid',
        };
    }
}
