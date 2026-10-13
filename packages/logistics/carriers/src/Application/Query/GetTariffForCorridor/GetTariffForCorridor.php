<?php

declare(strict_types=1);

namespace Logistics\Carriers\Application\Query\GetTariffForCorridor;

use Logistics\Shared\Application\Query\Query;

/**
 * Pide la tarifa de un transportista concreto para un corredor y clase de vehículo.
 */
final readonly class GetTariffForCorridor implements Query
{
    public function __construct(
        public string $carrierId,
        public string $corridorFrom,
        public string $corridorTo,
        public string $corridorType,
        public string $vehicleClass,
    ) {}
}
