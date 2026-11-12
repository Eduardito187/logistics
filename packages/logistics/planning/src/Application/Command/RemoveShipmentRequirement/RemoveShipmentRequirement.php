<?php

declare(strict_types=1);

namespace Logistics\Planning\Application\Command\RemoveShipmentRequirement;

use Logistics\Shared\Application\Command\Command;

/**
 * Quita los requisitos de ruteo de un envío: vuelve a entrar en cualquier camión.
 */
final readonly class RemoveShipmentRequirement implements Command
{
    public function __construct(
        public string $shipmentId,
    ) {}
}
