<?php

declare(strict_types=1);

namespace Logistics\Tracking\Application\Command\MarkShipmentAtStore;

use Logistics\Shared\Application\Command\Command;

/**
 * Marca en el seguimiento que el pedido con retiro en tienda YA LLEGÓ a su tienda destino. No cambia el
 * estado visible: fija el momento de llegada (`at_store_at`), del que las vistas derivan `ready_for_pickup`.
 *
 * Lo despacha el reactor del outbox ante `execution.shipment_dropped_at_store`, que ya verificó que el
 * envío es de retiro en tienda y que el nodo es SU tienda destino.
 *
 * `occurredAt` es el momento del hecho en ISO 8601. Sin valor (o inválido) el handler usa el momento actual.
 *
 * Tolerante: sin seguimiento para el envío, o con el pedido ya entregado/anulado/devuelto, no hace nada.
 */
final readonly class MarkShipmentAtStore implements Command
{
    public function __construct(
        public string $shipmentId,
        public ?string $occurredAt = null,
    ) {}
}
