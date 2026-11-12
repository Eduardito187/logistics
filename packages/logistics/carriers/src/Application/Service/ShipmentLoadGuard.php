<?php

declare(strict_types=1);

namespace Logistics\Carriers\Application\Service;

use Logistics\Carriers\Application\Contract\CarrierAssignmentReader;
use Logistics\Carriers\Domain\Contract\CarrierTripRepository;
use Logistics\Carriers\Domain\Exception\CarriersException;
use Logistics\Carriers\Domain\ValueObject\CarrierTripId;

/**
 * Regla de aplicación compartida al cargar envíos en un viaje: cada envío debe EXISTIR (lado de lectura
 * de shipments, sin importar su agregado) y no estar ya en OTRO viaje vigente (un envío no viaja en dos
 * camiones a la vez).
 */
final readonly class ShipmentLoadGuard
{
    public function __construct(
        private CarrierAssignmentReader $reader,
        private CarrierTripRepository $trips,
    ) {}

    /**
     * @param list<string> $shipmentIds
     */
    public function assertLoadable(array $shipmentIds, CarrierTripId $tripId): void
    {
        if ($shipmentIds === []) {
            return;
        }

        $missing = array_values(array_diff($shipmentIds, $this->reader->existingShipmentIds($shipmentIds)));
        if ($missing !== []) {
            throw CarriersException::because('No existen los envíos: '.implode(', ', $missing).'.');
        }

        $held = $this->trips->shipmentsHeldByOtherActiveTrips($shipmentIds, $tripId);
        if ($held !== []) {
            throw CarriersException::because(
                'Estos envíos ya están en otro viaje vigente: '.implode(', ', array_keys($held)).'.',
            );
        }
    }
}
