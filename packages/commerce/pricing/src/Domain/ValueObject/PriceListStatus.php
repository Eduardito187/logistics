<?php

declare(strict_types=1);

namespace Commerce\Pricing\Domain\ValueObject;

/**
 * Estado del ciclo de vida de una lista de precios.
 *
 * El dominio decide las transiciones (ver PriceList): nace en Draft, pasa a Active cuando se publica y a
 * Archived cuando se retira. El enum solo enumera los estados válidos.
 */
enum PriceListStatus: string
{
    /** Borrador: se está armando, todavía no resuelve precios al público. */
    case Draft = 'draft';

    /** Activa: es la lista vigente de su canal; resuelve precios. */
    case Active = 'active';

    /** Archivada: retirada; no se le fijan más precios ni resuelve. */
    case Archived = 'archived';

    /**
     * Texto en inglés para el admin y la consola (regla 5 de CLAUDE.md).
     */
    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Active => 'Active',
            self::Archived => 'Archived',
        };
    }
}
