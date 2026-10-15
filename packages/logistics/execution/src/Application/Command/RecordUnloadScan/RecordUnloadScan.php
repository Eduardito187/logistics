<?php

declare(strict_types=1);

namespace Logistics\Execution\Application\Command\RecordUnloadScan;

use Logistics\Shared\Application\Command\Command;

/**
 * Registra la descarga guiada de un bulto en una parada (qué baja en cada punto).
 *
 * Idempotente por idempotencyKey: el reenvío del mismo escaneo (típico offline) no lo duplica. La
 * parada se referencia por id string. La entrega del envío ya debe existir (cargada o en tránsito).
 */
final readonly class RecordUnloadScan implements Command
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
