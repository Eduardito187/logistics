<?php

declare(strict_types=1);

namespace Logistics\Execution\Application\Command\OpenNodeReception;

use Logistics\Shared\Application\Command\Command;

/**
 * Abre la recepción de un envío en un nodo destino: anuncia qué bultos se esperan y por qué corredor.
 *
 * El corredor se deriva del tipo de nodo de origen y destino ('CD'/'STORE'). Idempotente por el par
 * envío+destino: si ya hay una recepción abierta, el handler la reutiliza sin duplicar.
 */
final readonly class OpenNodeReception implements Command
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
        public string $occurredAt,
    ) {}
}
