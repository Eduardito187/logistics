<?php

declare(strict_types=1);

namespace Logistics\Network\Domain\ValueObject;

/**
 * Tipo de nodo de la red logística.
 *
 * Más adelante pueden sumarse lockers o agentes; por ahora son estos tres (ver README del package).
 */
enum NodeType: string
{
    /** Centro de distribución: origen y pulmón del stock. */
    case DistributionCenter = 'distribution_center';

    /** Tienda física: recibe, despacha y permite recojo. */
    case Store = 'store';

    /** Punto de recojo dedicado (sin venta en piso). */
    case PickupPoint = 'pickup_point';

    /**
     * Etiqueta legible en inglés para el admin y la consola.
     */
    public function label(): string
    {
        return match ($this) {
            self::DistributionCenter => 'Distribution center',
            self::Store => 'Store',
            self::PickupPoint => 'Pickup point',
        };
    }
}
