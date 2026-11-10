<?php

declare(strict_types=1);

namespace Logistics\Execution\Application\Command\CaptureDeliveryEvidence;

use Logistics\Shared\Application\Command\Command;

/**
 * Captura una evidencia de entrega LIGADA AL CLIENTE (firma, documento, POD o daño).
 *
 * El archivo ya fue persistido en el almacenamiento (disk+path) y su SHA-256 calculado por la capa de
 * Presentation; acá viajan esas referencias más el snapshot del cliente, el GPS y el actor. El estado de
 * validación arranca PENDING: lo resuelve el validador asíncrono. Idempotente por idempotencyKey.
 */
final readonly class CaptureDeliveryEvidence implements Command
{
    public function __construct(
        public string $shipmentId,
        public string $kind,
        public string $storageDisk,
        public string $storagePath,
        public string $sha256,
        public ?string $customerId = null,
        public ?string $documentId = null,
        public ?string $customerName = null,
        public ?string $customerPhone = null,
        public ?float $lat = null,
        public ?float $lng = null,
        public string $deviceId = '',
        public string $userId = '',
        public ?string $capturedAt = null,
        public ?string $idempotencyKey = null,
    ) {}
}
