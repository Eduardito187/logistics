<?php

declare(strict_types=1);

namespace Logistics\Capacity\Application\Command\DefineCapacity;

use Logistics\Shared\Application\Command\Command;

/**
 * Intención de definir un cupo nuevo. DTO inmutable con datos primitivos: el handler los convierte en
 * los value objects del dominio y aplica las validaciones.
 *
 * Para un cupo de DESPACHO debe venir exactamente uno de nodeId/zoneId/municipalityId. Para un cupo de
 * RECEPCIÓN (inbound) debe venir warehouseId (con dockId opcional) y dejar los tres ejes de despacho en
 * null; el handler detecta el eje inbound por la presencia de warehouseId.
 */
final readonly class DefineCapacityCommand implements Command
{
    public function __construct(
        public ?string $nodeId,
        public ?string $zoneId,
        public string $date,
        public string $timeSlot,
        public string $unit,
        public float $total,
        public ?string $municipalityId = null,
        public ?string $warehouseId = null,
        public ?string $dockId = null,
    ) {}
}
