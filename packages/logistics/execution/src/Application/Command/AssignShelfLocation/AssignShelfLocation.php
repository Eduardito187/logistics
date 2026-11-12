<?php

declare(strict_types=1);

namespace Logistics\Execution\Application\Command\AssignShelfLocation;

use Logistics\Shared\Application\Command\Command;

/**
 * Ubica en estante uno o varios productos de la recepción de un envío en un nodo (p. ej. "A-03-2").
 *
 * Sirve tanto para un producto suelto como para un lote (misma ubicación para varios productos).
 * Idempotente: repetir la misma ubicación no hace nada, y una ubicación con hora anterior a la vigente
 * (reenvío tardío de la cola offline) se ignora.
 */
final readonly class AssignShelfLocation implements Command
{
    /**
     * @param list<string> $unitIds
     */
    public function __construct(
        public string $shipmentId,
        public string $nodeId,
        public array $unitIds,
        public string $location,
        public string $deviceId,
        public string $userId,
        public string $occurredAt,
    ) {}
}
