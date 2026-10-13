<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Application\Command\SetFuelPrice;

use Logistics\Shared\Application\Command\Command;

/**
 * Intención de fijar el precio por litro de un tipo de combustible, vigente desde una fecha.
 *
 * El tipo de combustible llega como su valor primitivo y el precio como centavos + moneda, para no
 * acoplar la entrada a los VO del dominio; el handler los convierte.
 */
final readonly class SetFuelPrice implements Command
{
    public function __construct(
        public string $fuelType,
        public int $priceCents,
        public string $currency,
        public string $effectiveFrom,
        public ?string $source = null,
    ) {}
}
