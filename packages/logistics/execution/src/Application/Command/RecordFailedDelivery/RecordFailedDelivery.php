<?php

declare(strict_types=1);

namespace Logistics\Execution\Application\Command\RecordFailedDelivery;

use Logistics\Shared\Application\Command\Command;

/**
 * Registra un intento de entrega fallido con su motivo tipificado y cierra la entrega como fallida.
 *
 * Idempotente por idempotencyKey. La entrega del envío ya debe existir (cargada o en tránsito).
 * El motivo (reason) es el value de FailureReason.
 */
final readonly class RecordFailedDelivery implements Command
{
    public function __construct(
        public string $shipmentId,
        public string $unitId,
        public string $reason,
        public float $lat,
        public float $lng,
        public string $deviceId,
        public string $userId,
        public string $occurredAt,
        public string $idempotencyKey,
    ) {}
}
