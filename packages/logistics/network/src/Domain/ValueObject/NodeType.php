<?php

declare(strict_types=1);

namespace Logistics\Network\Domain\ValueObject;

use Logistics\Shared\Domain\Enum\CaseInsensitiveEnum;

/**
 * Tipo de nodo de la red logística.
 *
 * Más adelante pueden sumarse lockers o agentes; por ahora son estos cinco (ver README del package).
 */
enum NodeType: string
{
    use CaseInsensitiveEnum;

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
     * Almacén de una transportadora: el punto donde nuestra flota propia DEJA la carga para que un
     * tercero haga el tramo largo (modelo tercerizado). Es un hub de handoff: recibe nuestra mercadería
     * (inbound) y desde él continúa el tramo third_party. No ofrece corredor terminal por sí mismo.
     */
    case CarrierHub = 'carrier_hub';

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
            self::CarrierHub => 'Carrier hub',
        };
    }

    /**
     * ¿El nodo recibe carga como un hub? El almacén central recibe de proveedores y el almacén de la
     * transportadora recibe nuestro handoff; ambos habilitan andenes de recepción. El CD, la tienda y
     * el punto de recojo no.
     */
    public function receivesInbound(): bool
    {
        return $this === self::CentralWarehouse || $this === self::CarrierHub;
    }
}
