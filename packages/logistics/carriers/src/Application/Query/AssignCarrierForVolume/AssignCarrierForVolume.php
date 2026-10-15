<?php

declare(strict_types=1);

namespace Logistics\Carriers\Application\Query\AssignCarrierForVolume;

use Logistics\Shared\Application\Query\Query;

/**
 * Pide la asignación de un corredor por volumen: qué clase de vehículo corresponde y cuál es la tarifa
 * más barata disponible para esa clase (consolidación entre departamentos, P2-CAR-03).
 */
final readonly class AssignCarrierForVolume implements Query
{
    public function __construct(
        public string $corridorFrom,
        public string $corridorTo,
        public string $corridorType,
        public float $volumeM3,
    ) {}
}
