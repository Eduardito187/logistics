<?php

declare(strict_types=1);

namespace Logistics\Capacity\Application\Command\ReleaseCapacity;

use Logistics\Shared\Application\Command\Command;

/**
 * Intención de liberar capacidad de un cupo (p. ej. al reprogramar, cuando un envío falla o cuando se
 * cancela un ASN de recepción). Identifica el bucket por su clave natural (scope/fecha/franja/unidad) y
 * la cantidad a devolver.
 *
 * Despacho: exactamente uno de nodeId/zoneId/municipalityId. Recepción (inbound): warehouseId (con dockId
 * opcional) y los tres ejes de despacho en null; el handler detecta el eje inbound por warehouseId.
 */
final readonly class ReleaseCapacityCommand implements Command
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
