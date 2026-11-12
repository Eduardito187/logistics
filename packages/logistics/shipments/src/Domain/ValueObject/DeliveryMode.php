<?php

declare(strict_types=1);

namespace Logistics\Shipments\Domain\ValueObject;

/**
 * Modalidad de entrega del envío: reparto a domicilio por un chofer o recojo del cliente en tienda.
 *
 * No se persiste: se DERIVA de la regla del nodo destino que ya aplica el ingreso (ver
 * ReceiveShipmentHandler::destinationNodeOf y SourceReference::pickupNodeId). Un envío sin nodo destino
 * es a domicilio; uno cuyo nodo destino es una TIENDA o un PUNTO DE RECOJO es recojo en tienda. Un nodo
 * destino de otro tipo (CD / almacén central: traspaso) o desconocido sigue siendo reparto a domicilio:
 * el cliente nunca retira en un CD, y el mostrador de tienda no debe ofrecer entregarlo.
 */
enum DeliveryMode: string
{
    /** Reparto a domicilio (última milla con chofer). */
    case HomeDelivery = 'home_delivery';

    /** Recojo del cliente en una tienda o punto de recojo. */
    case StorePickup = 'store_pickup';

    /**
     * Tipos de nodo de red (valor de `network_nodes.type`) en los que el cliente retira el pedido.
     *
     * @return list<string>
     */
    public static function pickupNodeTypes(): array
    {
        return ['store', 'pickup_point'];
    }

    /**
     * Modalidad según el tipo del nodo destino (null = sin nodo destino o nodo desconocido).
     */
    public static function forDestinationNodeType(?string $nodeType): self
    {
        if ($nodeType !== null && in_array(strtolower(trim($nodeType)), self::pickupNodeTypes(), true)) {
            return self::StorePickup;
        }

        return self::HomeDelivery;
    }
}
