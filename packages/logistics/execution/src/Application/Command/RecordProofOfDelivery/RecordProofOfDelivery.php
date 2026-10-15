<?php

declare(strict_types=1);

namespace Logistics\Execution\Application\Command\RecordProofOfDelivery;

use Logistics\Shared\Application\Command\Command;

/**
 * Registra la prueba de entrega (QR del cliente o firma, y opcionalmente foto) y cierra la entrega.
 *
 * Idempotente por idempotencyKey. La entrega del envío ya debe existir (cargada o en tránsito).
 */
final readonly class RecordProofOfDelivery implements Command
{
    public function __construct(
        public string $shipmentId,
        public string $unitId,
        public string $confirmation,
        public ?string $photoUrl,
        public ?string $photoPath,
        public float $lat,
        public float $lng,
        public string $deviceId,
        public string $userId,
        public string $occurredAt,
        public string $idempotencyKey,
    ) {}
}
