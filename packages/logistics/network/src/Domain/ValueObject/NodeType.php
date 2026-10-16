<?php

declare(strict_types=1);

namespace Logistics\Network\Domain\ValueObject;

/**
 * Tipo de nodo de la red logística.
 *
 * Más adelante pueden sumarse lockers o agentes; por ahora son estos cuatro (ver README del package).
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
     * Almacén central: recibe mercadería de proveedores (inbound/ASN), la etiqueta y la distribuye como
     * un CD. Habilita los andenes de recepción (ReceivingDock) del marketplace (ver roadmap §5). Aguas
     * abajo se comporta como un centro de distribución.
     */
    case CentralWarehouse = 'central_warehouse';

    /**
     * Etiqueta legible en inglés para el admin y la consola.
     */
    public function label(): string
    {
        return match ($this) {
            self::DistributionCenter => 'Distribution center',
            self::Store => 'Store',
            self::PickupPoint => 'Pickup point',
            self::CentralWarehouse => 'Central warehouse',
        };
    }

    /**
     * Un almacén central recibe de proveedores: es el único tipo que habilita andenes de recepción.
     */
    public function receivesInbound(): bool
    {
        return $this === self::CentralWarehouse;
    }
}
