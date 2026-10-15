<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Application\Command\CompleteMaintenance;

use Logistics\Shared\Application\Command\Command;

/**
 * Intención de completar una orden de mantenimiento con sus ítems (repuestos y mano de obra).
 *
 * Los ítems llegan como primitivos; el handler los convierte a MaintenanceItem con la moneda indicada,
 * y el agregado calcula el costo total sumando cada `lineTotal()`.
 */
final readonly class CompleteMaintenance implements Command
{
    /**
     * @param list<array{description: string, qty: int, part_cost_cents: int, labor_cost_cents: int}> $items
     */
    public function __construct(
        public string $orderId,
        public array $items,
        public string $currency,
    ) {}
}
