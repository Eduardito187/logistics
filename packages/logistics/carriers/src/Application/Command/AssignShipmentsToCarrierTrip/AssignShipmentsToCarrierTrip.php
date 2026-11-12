<?php

declare(strict_types=1);

namespace Logistics\Carriers\Application\Command\AssignShipmentsToCarrierTrip;

use Logistics\Shared\Application\Command\Command;

/**
 * Intención de la oficina de agregar envíos a la carga de un viaje programado.
 */
final readonly class AssignShipmentsToCarrierTrip implements Command
{
    /**
     * @param list<string> $shipmentIds
     */
    public function __construct(
        public string $tripId,
        public array $shipmentIds,
    ) {}
}
