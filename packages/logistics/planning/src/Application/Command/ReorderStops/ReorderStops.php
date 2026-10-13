<?php

declare(strict_types=1);

namespace Logistics\Planning\Application\Command\ReorderStops;

use Logistics\Shared\Application\Command\Command;

/**
 * Ajuste humano de la secuencia de reparto: reordena las paradas del plan al orden dado. La lista debe
 * contener exactamente los envíos del plan, en el nuevo orden.
 */
final readonly class ReorderStops implements Command
{
    /**
     * @param list<string> $orderedShipmentIds
     */
    public function __construct(
        public string $planId,
        public array $orderedShipmentIds,
    ) {}
}
