<?php

declare(strict_types=1);

namespace Logistics\Execution\Application\Command\RecordPreparationScan;

use Logistics\Shared\Application\Command\Command;

/**
 * Registra un escaneo de preparación (EAN → etiqueta → carril) de un bulto.
 *
 * Idempotente por idempotencyKey: el reenvío del mismo escaneo (típico offline) no lo duplica.
 * Si la entrega del envío todavía no existe, se abre con el vehículo indicado.
 */
final readonly class RecordPreparationScan implements Command
{
    public function __construct(
        public string $shipmentId,
        public string $vehicleId,
        public string $unitId,
        public float $lat,
        public float $lng,
        public string $deviceId,
        public string $userId,
        public string $occurredAt,
        public string $idempotencyKey,
    ) {}
}
