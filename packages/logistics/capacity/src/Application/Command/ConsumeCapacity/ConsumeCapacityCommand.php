<?php

declare(strict_types=1);

namespace Logistics\Capacity\Application\Command\ConsumeCapacity;

use Logistics\Shared\Application\Command\Command;

/**
 * Intención de consumir capacidad de un cupo (p. ej. al planificar un envío o al agendar un ASN de
 * recepción). Identifica el bucket por su clave natural (scope/fecha/franja/unidad) y la cantidad a
 * imputar.
 *
 * Despacho: exactamente uno de nodeId/zoneId/municipalityId. Recepción (inbound): warehouseId (con dockId
 * opcional) y los tres ejes de despacho en null; el handler detecta el eje inbound por warehouseId.
 */
final readonly class ConsumeCapacityCommand implements Command
{
    public function __construct(
        public ?string $nodeId,
        public ?string $zoneId,
        public string $date,
        public string $timeSlot,
        public string $unit,
        public float $amount,
        public ?string $municipalityId = null,
        public ?string $warehouseId = null,
        public ?string $dockId = null,
    ) {}
}
