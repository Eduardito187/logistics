<?php

declare(strict_types=1);

namespace Commerce\Payments\Domain\ValueObject;

/**
 * Estado del ciclo de vida de un pago.
 *
 * El dominio decide las transiciones (ver Payment): nace en Pending, pasa a Authorized si la pasarela
 * aprueba online, a Captured al confirmarse el cobro, a Failed si se rechaza y a Refunded si se reversa
 * un cobro ya capturado. El enum solo enumera los estados válidos.
 */
enum PaymentStatus: string
{
    /** Iniciado: a la espera de confirmación de la pasarela (p. ej. QR por webhook). */
    case Pending = 'pending';

    /** Autorizado: la pasarela aprobó el cobro online, aún no capturado. */
    case Authorized = 'authorized';

    /** Capturado: el cobro se confirmó (dispara `payments.captured` → orden pagada). */
    case Captured = 'captured';

    /** Fallido: la pasarela rechazó el cobro. */
    case Failed = 'failed';

    /** Reembolsado: un cobro capturado se reversó. */
    case Refunded = 'refunded';

    /**
     * Texto en inglés para el admin y la consola (regla 5 de CLAUDE.md).
     */
    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Authorized => 'Authorized',
            self::Captured => 'Captured',
            self::Failed => 'Failed',
            self::Refunded => 'Refunded',
        };
    }
}
