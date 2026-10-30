<?php

declare(strict_types=1);

namespace Commerce\Payments\Domain\ValueObject;

/**
 * Método de pago con el que se intenta cobrar una orden.
 *
 * El enum solo enumera los medios soportados; cada uno se cobra detrás del puerto `PaymentGateway`.
 * QR BCB y transferencia suelen confirmarse async (vía webhook → capture); tarjeta se autoriza online.
 */
enum PaymentMethod: string
{
    /** QR Simple del Banco Central de Bolivia (confirmación async por webhook). */
    case QrBcb = 'qr_bcb';

    /** Transferencia bancaria. */
    case BankTransfer = 'bank_transfer';

    /** Tarjeta de crédito/débito (autorización online). */
    case Card = 'card';

    /** Efectivo (p. ej. contra entrega). */
    case Cash = 'cash';

    /**
     * Texto en inglés para el admin y la consola (regla 5 de CLAUDE.md).
     */
    public function label(): string
    {
        return match ($this) {
            self::QrBcb => 'QR BCB',
            self::BankTransfer => 'Bank transfer',
            self::Card => 'Card',
            self::Cash => 'Cash',
        };
    }
}
