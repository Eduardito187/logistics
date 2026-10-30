<?php

declare(strict_types=1);

namespace Commerce\Pricing\Domain\ValueObject;

/**
 * Canal comercial al que aplica una lista de precios. Un mismo SKU puede valer distinto por canal.
 *
 * Retail (B2C, venta al público), B2B (mayorista / empresas) y C2C (marketplace entre particulares).
 */
enum Channel: string
{
    /** Venta al público (B2C). */
    case Retail = 'retail';

    /** Mayorista / empresas (B2B). */
    case B2B = 'b2b';

    /** Marketplace entre particulares (C2C). */
    case C2C = 'c2c';

    /**
     * Texto en inglés para el admin y la consola (regla 5 de CLAUDE.md).
     */
    public function label(): string
    {
        return match ($this) {
            self::Retail => 'Retail',
            self::B2B => 'B2B',
            self::C2C => 'C2C',
        };
    }
}
