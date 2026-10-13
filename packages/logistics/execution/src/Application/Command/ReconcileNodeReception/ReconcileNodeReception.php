<?php

declare(strict_types=1);

namespace Logistics\Execution\Application\Command\ReconcileNodeReception;

use Logistics\Shared\Application\Command\Command;

/**
 * Cierra la recepción en el nodo conciliando lo recibido contra lo esperado (faltantes/sobrantes).
 *
 * Idempotente: si la recepción ya estaba conciliada, el agregado no hace nada.
 */
final readonly class ReconcileNodeReception implements Command
{
    public function __construct(
        public string $shipmentId,
        public string $destinationNodeId,
        public string $deviceId,
        public string $userId,
        public string $occurredAt,
    ) {}
}
