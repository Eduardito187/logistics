<?php

declare(strict_types=1);

namespace Commerce\Invoicing\Domain\ValueObject;

/**
 * Estado del ciclo de vida de una factura.
 *
 * El dominio decide las transiciones (ver Invoice): nace en Draft al calcularse desde las líneas, pasa a
 * Issued cuando el sistema tributario le asigna número fiscal, y a Cancelled si se anula. El enum solo
 * enumera los estados válidos.
 */
enum InvoiceStatus: string
{
    /** Borrador: calculada desde las líneas, aún sin número fiscal. */
    case Draft = 'draft';

    /** Emitida: el sistema tributario asignó número fiscal (dispara `invoicing.invoice_issued`). */
    case Issued = 'issued';

    /** Anulada. */
    case Cancelled = 'cancelled';

    /**
     * Texto en inglés para el admin y la consola (regla 5 de CLAUDE.md).
     */
    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Issued => 'Issued',
            self::Cancelled => 'Cancelled',
        };
    }
}
