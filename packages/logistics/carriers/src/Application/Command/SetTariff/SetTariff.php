<?php

declare(strict_types=1);

namespace Logistics\Carriers\Application\Command\SetTariff;

use Logistics\Shared\Application\Command\Command;

/**
 * Intención de definir la tarifa de un transportista para un corredor y clase de vehículo.
 *
 * El corredor llega como sus tres primitivos (origen, destino, tipo) y el precio como centavos + moneda,
 * para no acoplar la entrada a los VO del dominio; el handler los convierte.
 */
final readonly class SetTariff implements Command
{
    public function __construct(
        public string $carrierId,
        public string $corridorFrom,
        public string $corridorTo,
        public string $corridorType,
        public string $vehicleClass,
        public int $priceCents,
        public string $currency,
        public string $effectiveFrom,
    ) {}
}
