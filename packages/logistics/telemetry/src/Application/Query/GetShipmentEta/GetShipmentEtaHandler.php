<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Application\Query\GetShipmentEta;

use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;
use Logistics\Telemetry\Application\Contract\ShipmentCustodyReader;
use Logistics\Telemetry\Application\Query\GetVehicleEtas\GetVehicleEtas;
use Logistics\Telemetry\Application\Query\GetVehicleEtas\GetVehicleEtasHandler;
use Logistics\Telemetry\Application\ReadModel\VehicleEtaView;

/**
 * ETA de un envío: resuelve el vehículo que lo lleva (custodia en execution) y recorta la ETA del vehículo a
 * la parada de ese envío. Sin entrega asignada → null. Ya entregado → vista sin paradas.
 *
 * @implements QueryHandler<GetShipmentEta, VehicleEtaView|null>
 */
final readonly class GetShipmentEtaHandler implements QueryHandler
{
    public function __construct(
        private ShipmentCustodyReader $custody,
        private GetVehicleEtasHandler $vehicleEtas,
    ) {}

    public function handle(Query $query): ?VehicleEtaView
    {
        assert($query instanceof GetShipmentEta);

        $custody = $this->custody->custodyOf($query->shipmentId);
        if ($custody === null || $custody['vehicle_id'] === '') {
            return null;
        }

        return $this->vehicleEtas
            ->handle(new GetVehicleEtas($custody['vehicle_id']))
            ->onlyShipment($query->shipmentId);
    }
}
