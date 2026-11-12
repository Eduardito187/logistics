<?php

declare(strict_types=1);

namespace Logistics\Execution\Application\Query\ResolveNodeReceptionTarget;

use Logistics\Shared\Application\Query\Query;

/**
 * Resuelve, en el servidor, los datos para abrir la recepción de un envío: nodo destino, nodo origen, sus
 * tipos (corredor) y los productos esperados. Lo que la app mande explícito se respeta; lo que falte se
 * deriva:
 *
 * - destino: el `destination_node_id` del envío o, si no está poblado, el nodo del usuario (fallback);
 * - origen: el nodo base del camión que trae el envío, si no el CD que abastece al destino por la red, si
 *   no el CD activo principal;
 * - esperados: los productos físicos del envío (shipment_units).
 */
final readonly class ResolveNodeReceptionTarget implements Query
{
    /**
     * @param list<string>|null $expectedUnitIds
     */
    public function __construct(
        public string $shipmentId,
        public ?string $originNodeId = null,
        public ?string $destinationNodeId = null,
        public ?string $originNodeKind = null,
        public ?string $destinationNodeKind = null,
        public ?array $expectedUnitIds = null,
        public ?string $fallbackDestinationNodeId = null,
    ) {}
}
