<?php

declare(strict_types=1);

namespace Logistics\Execution\Application\ReadModel;

/**
 * Datos resueltos para abrir una recepción en nodo (destino, origen, tipos de nodo y productos esperados),
 * en primitivos, listos para armar el comando OpenNodeReception.
 */
final readonly class NodeReceptionTarget
{
    /**
     * @param list<string> $expectedUnitIds
     */
    public function __construct(
        public string $shipmentId,
        public string $originNodeId,
        public string $destinationNodeId,
        public string $originNodeKind,
        public string $destinationNodeKind,
        public array $expectedUnitIds,
    ) {}
}
