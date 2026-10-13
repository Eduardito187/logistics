<?php

declare(strict_types=1);

namespace Logistics\Execution\Application\Command\RecordLoadScan;

use Logistics\Shared\Application\Command\Command;

/**
 * Registra un escaneo de carga validada de un bulto al vehículo.
 *
 * Idempotente por idempotencyKey. La entrega del envío ya debe existir (preparada).
 */
final readonly class RecordLoadScan implements Command
{
    public function __construct(
        public string $shipmentId,
        public string $unitId,
        public float $lat,
        public float $lng,
        public string $deviceId,
        public string $userId,
        public string $occurredAt,
        public string $idempotencyKey,
    ) {}
}
