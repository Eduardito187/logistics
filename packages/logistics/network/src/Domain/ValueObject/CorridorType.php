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
     * Tipo de nodo en el que TERMINA el corredor cuando une dos nodos de la red, o null cuando es un
     * corredor terminal (acaba en el cliente: CdToCustomer y StorePickup). Es el espejo de
     * originNodeType() y la base de la validación de extremos de un tramo.
     */
    public function destinationNodeType(): ?NodeType
    {
        return match ($this) {
            self::CdToCd, self::StoreToCd => NodeType::DistributionCenter,
            self::CdToStore, self::StoreToStore => NodeType::Store,
            self::CdToCustomer, self::StorePickup => null,
        };
    }

    /**
     * Un corredor que une dos nodos de la red (puede ser un tramo/Leg). Los corredores terminales
     * (CdToCustomer, StorePickup) acaban en el cliente, no en un nodo, así que NO son tramos.
     */
    public function connectsNodes(): bool
    {
        return $this->deliversToNode();
    }

    /**
     * Corredor terminal: acaba en el cliente y no en un nodo de la red. Se ofrece desde un único nodo
     * (el CD para última milla, la tienda para recojo) y no se modela como tramo entre nodos.
     */
    public function isTerminal(): bool
    {
        return !$this->connectsNodes();
    }

    /**
     * ¿Un tramo de este corredor entre un nodo de tipo $from y uno de tipo $to es válido? Un corredor
     * terminal nunca lo es (no une dos nodos); el resto exige que origen y destino calcen con los
     * tipos declarados. Es la regla pura que aplica Leg::define.
     */
    public function acceptsEndpoints(NodeType $from, NodeType $to): bool
    {
        return $this->connectsNodes()
            && $from === $this->originNodeType()
            && $to === $this->destinationNodeType();
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
