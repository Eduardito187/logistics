<?php

declare(strict_types=1);

namespace Logistics\Returns\Domain\ValueObject;

/**
 * Disposición de una devolución recibida: qué se hace físicamente con la mercadería (ADR 017 §7.4).
 */
enum Disposition: string
{
    /** Vuelve al stock: se re-ingresa al lote del dueño correcto (enganche con inventory ReceiveStock). */
    case Restock = 'restock';

    /** Se devuelve al seller (requiere dueño; típico de un RTV o de producto de marketplace no revendible). */
    case ReturnToSeller = 'return_to_seller';

    /** Se descarta (merma): no vuelve al stock ni al seller. */
    case Scrap = 'scrap';

    /**
     * Texto en inglés para el admin y la consola (regla 5 de CLAUDE.md).
     */
    public function label(): string
    {
        return match ($this) {
            self::Restock => 'Restock',
            self::ReturnToSeller => 'Return to seller',
            self::Scrap => 'Scrap',
        };
    }

    /**
     * Devolver al seller exige un dueño (seller): no se puede devolver producto retail a nadie.
     */
    public function requiresOwner(): bool
    {
        return $this === self::ReturnToSeller;
    }

    /**
     * ¿Esta disposición re-ingresa stock? Solo Restock dispara el enganche con inventory.
     */
    public function isRestock(): bool
    {
        return $this === self::Restock;
    }
}
