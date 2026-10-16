<?php

declare(strict_types=1);

namespace Logistics\Inventory\Domain\ValueObject;

/**
 * Tipo de movimiento del ledger de inventario (`inventory_movements`). Describe qué operación de dominio
 * originó cada asiento, para trazabilidad y reportería por dueño.
 */
enum MovementType: string
{
    /** Ingreso de stock nuevo (recepción FBD o alta propia). */
    case Received = 'received';

    /** Reserva: pasa de disponible a reservado (sin cambiar el on-hand). */
    case Reserved = 'reserved';

    /** Liberación de una reserva: vuelve de reservado a disponible. */
    case Released = 'released';

    /** Pick: la mercadería reservada sale físicamente (baja el on-hand). */
    case Picked = 'picked';

    /** Transferencia entre almacenes (salida del lote origen). */
    case Transferred = 'transferred';

    /** Ajuste de inventario (merma, hallazgo, corrección de conteo). */
    case Adjusted = 'adjusted';

    /**
     * Etiqueta legible en inglés para el admin y la consola.
     */
    public function label(): string
    {
        return match ($this) {
            self::Received => 'Received',
            self::Reserved => 'Reserved',
            self::Released => 'Released',
            self::Picked => 'Picked',
            self::Transferred => 'Transferred',
            self::Adjusted => 'Adjusted',
        };
    }
}
