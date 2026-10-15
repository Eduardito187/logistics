<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Application\Command\UpdateFuelPrice;

use Logistics\Shared\Application\Command\Command;

/**
 * Intención de editar un precio de combustible: precio (centavos + moneda), vigencia, fuente y,
 * opcionalmente, el tipo de combustible.
 *
 * El precio llega como centavos + moneda y la vigencia como texto 'Y-m-d', para no acoplar la entrada a
 * los VO del dominio; el handler los convierte.
 */
final readonly class UpdateFuelPrice implements Command
{
    public function __construct(
        public string $id,
        public int $priceCents,
        public string $currency,
        public string $effectiveFrom,
        public ?string $source = null,
        public ?string $fuelType = null,
    ) {}
}
