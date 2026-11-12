<?php

declare(strict_types=1);

namespace Logistics\Execution\Application\Command\ReceiveUnitAtNode;

use Logistics\Shared\Application\Command\Command;

/**
 * Escanea la recepción de un bulto en el nodo destino (hora/GPS/usuario/dispositivo, hecho inmutable).
 *
 * Idempotente por idempotencyKey. La recepción del envío en ese nodo ya debe estar abierta.
 *
 * lat/lng son opcionales (sin señal en interiores): null significa "sin GPS" y nunca se guarda 0,0. Si hay
 * fix puede venir su precisión en metros y el instante en que se capturó (ISO 8601).
 */
final readonly class ReceiveUnitAtNode implements Command
{
    public function __construct(
        public string $shipmentId,
        public string $destinationNodeId,
        public string $unitId,
        public ?float $lat,
        public ?float $lng,
        public string $deviceId,
        public string $userId,
        public string $occurredAt,
        public string $idempotencyKey,
        public ?float $accuracyM = null,
        public ?string $locationCapturedAt = null,
    ) {}
}
