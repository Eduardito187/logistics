<?php

declare(strict_types=1);

namespace Logistics\Carriers\Application\Query\CheapestCarrierForCorridor;

use Logistics\Shared\Application\Query\Query;

/**
 * Pide la tarifa más barata disponible para un corredor (para consolidar por costo entre departamentos).
 */
final readonly class CheapestCarrierForCorridor implements Query
{
    public function __construct(
        public string $corridorFrom,
        public string $corridorTo,
        public string $corridorType,
    ) {}
}
