<?php

declare(strict_types=1);

namespace Logistics\Execution\Application\Command\RecordNodeReception;

use Logistics\Shared\Application\Command\Command;

/**
 * Registra la recepción de un bulto en un nodo logístico (paso por nodo).
 *
 * Idempotente por idempotencyKey. El nodo se referencia por id string. La entrega del envío ya debe
 * existir (cargada o en tránsito).
 */
final readonly class RecordNodeReception implements Command
{
    public function __construct(
        public string $shipmentId,
        public string $nodeId,
        public string $unitId,
        public float $lat,
        public float $lng,
        public string $deviceId,
        public string $userId,
        public string $occurredAt,
        public string $idempotencyKey,
    ) {}
}
