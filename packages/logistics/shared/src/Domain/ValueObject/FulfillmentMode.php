<?php

declare(strict_types=1);

namespace Logistics\Shared\Domain\ValueObject;

/**
 * Modo de fulfillment de una unidad/envío: define quién es el dueño del producto y cómo entra a la
 * operación. Es la dimensión transversal que habilita marketplace/dropship sin romper el flujo retail
 * (ver ADR 017 y docs/marketplace-dropship-roadmap.md). Por defecto todo es `Owned` (retail, Dismac dueño).
 */
enum FulfillmentMode: string
{
    /** Retail: el producto es de Dismac (comportamiento histórico). */
    case Owned = 'owned';

    /** Marketplace "Fulfilled by Dismac": el seller lo deja en un almacén central y Dismac lo distribuye. */
    case Fbd = 'fbd';

    /** Dropship: el seller despacha directamente; Dismac coordina y/o etiqueta. */
    case Dropship = 'dropship';

    /**
     * Etiqueta legible en inglés para el admin y la consola.
     */
    public function label(): string
    {
        return match ($this) {
            self::Owned => 'Owned (retail)',
            self::Fbd => 'Fulfilled by Dismac',
            self::Dropship => 'Dropship',
        };
    }

    /**
     * Un modo de marketplace (FBD o dropship) exige un dueño (seller); retail no.
     */
    public function requiresOwner(): bool
    {
        return $this !== self::Owned;
    }

    /**
     * ¿Es producto de un tercero (no de Dismac)?
     */
    public function isMarketplace(): bool
    {
        return $this !== self::Owned;
    }
}
