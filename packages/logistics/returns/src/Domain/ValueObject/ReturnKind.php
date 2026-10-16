<?php

declare(strict_types=1);

namespace Logistics\Returns\Domain\ValueObject;

/**
 * Tipo de devolución (logística inversa, ADR 017 §7.4).
 *
 * El tipo decide quién origina el retorno; la DISPOSICIÓN (ver Disposition) decide qué se hace con la
 * mercadería una vez recibida. Son dimensiones ortogonales: una devolución de cliente puede terminar en
 * restock, devuelta al seller o descartada.
 */
enum ReturnKind: string
{
    /** Devolución de cliente: el cliente retorna el producto al almacén central. */
    case CustomerReturn = 'customer_return';

    /** Devolución al proveedor (RTV, return-to-vendor): se devuelve el producto al seller. */
    case ReturnToVendor = 'return_to_vendor';

    /**
     * Texto en inglés para el admin y la consola (regla 5 de CLAUDE.md).
     */
    public function label(): string
    {
        return match ($this) {
            self::CustomerReturn => 'Customer return',
            self::ReturnToVendor => 'Return to vendor',
        };
    }

    /**
     * Un RTV siempre es de un seller (hay un proveedor al que devolver): exige dueño.
     */
    public function requiresOwner(): bool
    {
        return $this === self::ReturnToVendor;
    }
}
