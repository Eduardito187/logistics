<?php

declare(strict_types=1);

namespace Logistics\Execution\Application\Command\RecordProofOfDelivery;

use Logistics\Shared\Application\Command\Command;

/**
 * Registra la prueba de entrega (QR del cliente o firma, y opcionalmente foto) y cierra la entrega.
 *
 * Idempotente por idempotencyKey. La entrega del envío ya debe existir (cargada o en tránsito).
 *
 * POD en LOTE: `unitIds` cierra la entrega con todos esos bultos en una sola confirmación (si viene vacío,
 * se usa `unitId`). GPS opcional (null = sin señal; 0,0 se descarta). `photoPaths`/`evidenceIds` vinculan
 * las evidencias ya subidas por la app.
 */
final readonly class RecordProofOfDelivery implements Command
{
    /**
     * @param list<string> $unitIds
     * @param list<string> $photoPaths
     * @param list<string> $evidenceIds
     */
    public function __construct(
        public string $shipmentId,
        public string $unitId,
        public string $confirmation,
        public ?string $photoUrl,
        public ?string $photoPath,
        public ?float $lat,
        public ?float $lng,
        public string $deviceId,
        public string $userId,
        public string $occurredAt,
        public string $idempotencyKey,
        public array $unitIds = [],
        public array $photoPaths = [],
        public array $evidenceIds = [],
        public ?float $accuracyM = null,
        public ?string $locationCapturedAt = null,
        // Entrega de MOSTRADOR (rol tienda, retiro en tienda): autoriza a abrir la entrega sin vehículo.
        public bool $counterHandover = false,
    ) {}
}
