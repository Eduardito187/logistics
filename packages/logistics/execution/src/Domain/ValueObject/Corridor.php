<?php

declare(strict_types=1);

namespace Logistics\Execution\Domain\ValueObject;

/**
 * Corredor logístico entre nodos: clasifica el tramo según el tipo de nodo de origen y destino. La
 * recepción en nodo cubre los cuatro corredores internos de la red (CD→CD, CD→tienda, tienda→tienda,
 * tienda→CD). La entrega al cliente (CD→cliente) NO es un corredor entre nodos: la gobierna el agregado
 * Delivery, no esta recepción.
 */
enum Corridor: string
{
    /** Centro de distribución → centro de distribución. */
    case CdToCd = 'CD_TO_CD';

    /** Centro de distribución → tienda. */
    case CdToStore = 'CD_TO_STORE';

    /** Tienda → tienda. */
    case StoreToStore = 'STORE_TO_STORE';

    /** Tienda → centro de distribución. */
    case StoreToCd = 'STORE_TO_CD';

    /**
     * Deriva el corredor a partir del tipo de nodo de origen y destino. Cubre las cuatro combinaciones
     * entre CD y tienda.
     */
    public static function between(NodeKind $origin, NodeKind $destination): self
    {
        return match ([$origin, $destination]) {
            [NodeKind::Cd, NodeKind::Cd] => self::CdToCd,
            [NodeKind::Cd, NodeKind::Store] => self::CdToStore,
            [NodeKind::Store, NodeKind::Store] => self::StoreToStore,
            [NodeKind::Store, NodeKind::Cd] => self::StoreToCd,
        };
    }
}
