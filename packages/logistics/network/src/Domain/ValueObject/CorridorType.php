<?php

declare(strict_types=1);

namespace Logistics\Network\Domain\ValueObject;

/**
 * Los seis corredores posibles entre nodos (y hacia el cliente).
 *
 * Un envío es una secuencia de tramos; cada tramo pertenece a uno de estos corredores. network solo
 * los nombra y los valida: NO decide por cuál corredor va un pedido (eso es planning).
 */
enum CorridorType: string
{
    /** CD → cliente: entrega de última milla desde un centro de distribución. */
    case CdToCustomer = 'cd_to_customer';

    /** CD → CD: traspaso entre centros de distribución. */
    case CdToCd = 'cd_to_cd';

    /** CD → tienda: reposición o preparación hacia una tienda. */
    case CdToStore = 'cd_to_store';

    /** Tienda → tienda: traspaso entre tiendas. */
    case StoreToStore = 'store_to_store';

    /** Tienda → CD: devolución o consolidación hacia un centro de distribución. */
    case StoreToCd = 'store_to_cd';

    /** Recojo en tienda: el cliente retira en la tienda (no hay tramo de salida). */
    case StorePickup = 'store_pickup';

    /**
     * Tipo de nodo en el que arranca el corredor. Todos nacen en un nodo real.
     */
    public function originNodeType(): NodeType
    {
        return match ($this) {
            self::CdToCustomer, self::CdToCd, self::CdToStore => NodeType::DistributionCenter,
            self::StoreToStore, self::StoreToCd, self::StorePickup => NodeType::Store,
        };
    }

    /**
     * Si el corredor termina en otro nodo de la red. CdToCustomer y StorePickup terminan en el
     * cliente, no en un nodo, así que devuelven false.
     */
    public function deliversToNode(): bool
    {
        return match ($this) {
            self::CdToCustomer, self::StorePickup => false,
            self::CdToCd, self::CdToStore, self::StoreToStore, self::StoreToCd => true,
        };
    }

    /**
     * Etiqueta legible en inglés para el admin y la consola.
     */
    public function label(): string
    {
        return match ($this) {
            self::CdToCustomer => 'DC to customer',
            self::CdToCd => 'DC to DC',
            self::CdToStore => 'DC to store',
            self::StoreToStore => 'Store to store',
            self::StoreToCd => 'Store to DC',
            self::StorePickup => 'Store pickup',
        };
    }
}
